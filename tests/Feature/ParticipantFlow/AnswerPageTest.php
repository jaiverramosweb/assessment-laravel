<?php

namespace Tests\Feature\ParticipantFlow;

use App\Auth\ParticipantSessions;
use App\Models\Answer;
use App\Models\ParticipantSession;
use App\Models\Submission;
use App\Services\ParticipantFlow\Answers;
use Inertia\Testing\AssertableInertia as Assert;

/** HTTP behavior of /a/{slug}/responder: load, autosave (guardar) and final submission (entregar). */
class AnswerPageTest extends ParticipantFlowTestCase
{
    private array $ctx;

    private array $qs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->login();
        $this->qs = Answers::questionsFor($this->assessment->id);
    }

    private function url(string $suffix = ''): string
    {
        return '/a/'.$this->assessment->slug.'/responder'.$suffix;
    }

    private function asParticipant(): static
    {
        return $this->withUnencryptedCookie(ParticipantSessions::COOKIE, $this->ctx['token']);
    }

    private function autosave(array $fields)
    {
        return $this->asParticipant()->withCredentials()->postJson($this->url('/guardar'), $fields);
    }

    private function deliver(array $fields)
    {
        return $this->asParticipant()->from($this->url())->post($this->url('/entregar'), $fields);
    }

    private function sessionCookie($response)
    {
        return collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === ParticipantSessions::COOKIE);
    }

    public function test_without_session_redirects_to_the_access_page(): void
    {
        $this->get($this->url())->assertStatus(303)->assertRedirect('/a/'.$this->assessment->slug);
        $this->withUnencryptedCookie(ParticipantSessions::COOKIE, 'inventado')
            ->get($this->url())->assertRedirect('/a/'.$this->assessment->slug);
        $this->postJson($this->url('/guardar'), [])
            ->assertOk()->assertExactJson(['type' => 'redirect', 'location' => '/a/'.$this->assessment->slug]);
        $this->post($this->url('/entregar'))->assertRedirect('/a/'.$this->assessment->slug);
    }

    public function test_session_of_another_assessment_is_not_valid_here(): void
    {
        $other = $this->createAssessment(['status' => 'publicado']);
        $this->asParticipant()->get('/a/'.$other->slug.'/responder')->assertRedirect('/a/'.$other->slug);
    }

    public function test_closed_assessment_redirects_to_the_access_page(): void
    {
        $this->assessment->update(['ends_at' => self::NOW]);
        $this->asParticipant()->get($this->url())->assertRedirect('/a/'.$this->assessment->slug);
    }

    public function test_page_props_never_contain_the_correct_option_and_renew_the_cookie(): void
    {
        $response = $this->asParticipant()->get($this->url());

        $response->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Participant/Answer')
            ->where('assessment.title', 'Seguridad')
            ->where('assessment.openMaxChars', 500)
            ->where('assessment.endsAt', self::ENDS)
            ->where('participant.name', 'Ana Pérez')
            ->where('participant.email', 'ana@x.co')
            ->has('questions', 3)
            ->has('questions.0.options', 2)
            ->where('answers', [])
            ->where('savedAt', self::NOW)
            ->where('form', null)
            ->etc());
        $this->assertDoesNotMatchRegularExpression('/is_?correct/i', $response->getContent());
        $cookie = $this->sessionCookie($response);
        $this->assertSame('/a/'.$this->assessment->slug, $cookie->getPath());
        $this->assertSame($this->ctx['token'], $cookie->getValue());
    }

    public function test_autosave_persists_answers_and_returns_json(): void
    {
        [$mc, $open] = $this->qs;
        $this->autosave(['q_'.$mc['id'] => $mc['options'][1]['id'], 'q_'.$open['id'] => ' Texto con espacios '])
            ->assertOk()->assertJson(['type' => 'success', 'savedAt' => self::NOW]);

        $this->assertSame(2, Answer::count());
        $this->assertSame(' Texto con espacios ', Answer::where('question_id', $open['id'])->value('text'));

        $this->asParticipant()->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('answers.'.$mc['id'].'.optionId', $mc['options'][1]['id'])
            ->where('answers.'.$open['id'].'.text', ' Texto con espacios ')
            ->etc());

        // An empty value deletes the answer.
        $this->autosave(['q_'.$open['id'] => ''])->assertOk();
        $this->assertSame(1, Answer::count());
    }

    public function test_autosave_validation_errors_are_400_with_the_original_messages(): void
    {
        [$mc, $open] = $this->qs;
        $this->autosave(['q_'.$mc['id'] => 'falsa', 'q_'.$open['id'] => str_repeat('x', 501)])
            ->assertStatus(400)
            ->assertExactJson(['type' => 'failure', 'errors' => [
                'Pregunta 1: opción no válida.',
                'Pregunta 2: supera el máximo de 500 caracteres.',
            ]]);
        $this->assertSame(0, Answer::count());
    }

    public function test_submission_with_missing_required_questions_is_refused(): void
    {
        [$mc] = $this->qs;
        $this->deliver(['q_'.$mc['id'] => $mc['options'][0]['id']])
            ->assertStatus(303)->assertRedirect($this->url());

        $this->assertNull(Submission::find($this->ctx['submission']->id)->submitted_at);
        $this->assertSame(1, Answer::count());
        $this->asParticipant()->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('form.missing', [2])
            ->where('form.errors', ['Falta responder la pregunta obligatoria 2.'])
            ->etc());

        $this->deliver(['q_'.$mc['id'] => '']);
        $this->asParticipant()->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('form.missing', [1, 2])
            ->where('form.errors', ['Faltan preguntas obligatorias por responder: 1, 2.'])
            ->etc());
    }

    public function test_submission_validation_errors_are_shown(): void
    {
        [$mc] = $this->qs;
        $this->deliver(['q_'.$mc['id'] => 'falsa'])->assertRedirect($this->url());
        $this->asParticipant()->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('form.missing', [])
            ->where('form.errors', ['Pregunta 1: opción no válida.'])
            ->etc());
    }

    public function test_successful_submission_closes_the_session_and_refuses_a_second_one(): void
    {
        [$mc, $open] = $this->qs;
        $response = $this->deliver(['q_'.$mc['id'] => $mc['options'][0]['id'], 'q_'.$open['id'] => 'Mi respuesta']);

        $response->assertStatus(303)->assertRedirect('/a/'.$this->assessment->slug.'/entregado');
        $cookie = $this->sessionCookie($response);
        $this->assertSame('', (string) $cookie->getValue());
        $this->assertSame('/a/'.$this->assessment->slug, $cookie->getPath());
        $this->assertTrue($cookie->isCleared());

        $this->assertSame(self::NOW, Submission::find($this->ctx['submission']->id)->submitted_at);
        $this->assertSame(0, ParticipantSession::count());

        // The old token no longer works: no more saving or submitting.
        $this->deliver(['q_'.$open['id'] => 'cambio'])->assertRedirect('/a/'.$this->assessment->slug);
        $this->autosave(['q_'.$open['id'] => 'cambio'])->assertJson(['type' => 'redirect']);
        $this->assertSame('Mi respuesta', Answer::where('question_id', $open['id'])->value('text'));
        $this->assertSame(1, Submission::count());
    }

    public function test_a_session_whose_submission_was_delivered_goes_to_the_delivered_page(): void
    {
        // E.g. a second tab: the session survives but the submission is already delivered.
        Submission::where('id', $this->ctx['submission']->id)->update(['submitted_at' => self::NOW]);

        $this->asParticipant()->get($this->url())->assertRedirect('/a/'.$this->assessment->slug.'/entregado');
        $this->autosave([])->assertExactJson(['type' => 'redirect', 'location' => '/a/'.$this->assessment->slug.'/entregado']);
    }

    public function test_double_submission_is_refused_by_the_database_condition(): void
    {
        [$mc, $open] = $this->qs;
        Answers::save($this->ctx, [
            ['questionId' => $mc['id'], 'value' => $mc['options'][0]['id']],
            ['questionId' => $open['id'], 'value' => 'Mi respuesta'],
        ]);
        // Simulates a concurrent request that already delivered after this one loaded its context.
        Submission::where('id', $this->ctx['submission']->id)->update(['submitted_at' => self::NOW - 5]);

        $this->expectException(\App\Services\ParticipantFlow\AlreadySubmittedException::class);
        Answers::submit($this->ctx, self::NOW);
    }
}
