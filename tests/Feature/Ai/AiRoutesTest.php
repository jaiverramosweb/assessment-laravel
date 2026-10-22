<?php

namespace Tests\Feature\Ai;

use App\Jobs\AnalyzeOpenAnswers;
use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Services\Ai\AiReportProps;
use App\Services\Ai\AiStore;
use App\Services\Ai\Analysis\AiJobState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** POST /admin/assessments/{id}/ia/analizar, the queued job and the props of the reports page. */
class AiRoutesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenAnswers;

    private const URL = 'https://api.anthropic.com/v1/messages?beta=true';

    private string $route;

    /** Replaces every previous stub (Http::fake() keeps the first matching one). */
    private function stubApi(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['assessments.anthropic.api_key' => 'sk-ant-prueba', 'assessments.anthropic.model' => 'claude-opus-5-5']);
        $this->seedOpenAnswers();
        $this->route = "/admin/assessments/{$this->assessment->id}/ia/analizar";
    }

    private function fakeApi(): void
    {
        $this->stubApi([self::URL => Http::response([
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => json_encode(self::aiResult())]],
            'stop_reason' => 'end_turn',
        ])]);
    }

    private function props(): array
    {
        return AiReportProps::for($this->assessment->fresh());
    }

    public function test_requires_an_admin_session(): void
    {
        Queue::fake();
        $this->post($this->route)->assertRedirect();
        Queue::assertNothingPushed();
    }

    public function test_unknown_assessment_is_404(): void
    {
        $this->actingAsAdmin($this->admin);
        $this->post('/admin/assessments/00000000-0000-0000-0000-000000000000/ia/analizar')->assertNotFound();
    }

    public function test_without_api_key_it_explains_how_to_enable_it(): void
    {
        Queue::fake();
        config(['assessments.anthropic.api_key' => null]);
        $this->actingAsAdmin($this->admin);
        $this->post($this->route, ['questionId' => $this->openIds[0]])
            ->assertRedirect()
            ->assertSessionHas('aiError', ['target' => $this->openIds[0], 'message' => 'Configure ANTHROPIC_API_KEY en .env para usar la IA.']);
        Queue::assertNothingPushed();
        $this->assertFalse($this->props()['ai']['configured']);
    }

    public function test_validates_the_question_before_queueing(): void
    {
        Queue::fake();
        $this->actingAsAdmin($this->admin);
        $this->post($this->route, ['questionId' => 'no-existe'])
            ->assertSessionHas('aiError', ['target' => 'no-existe', 'message' => 'La pregunta no existe o no es de respuesta abierta.']);
        $this->post($this->route, ['questionId' => ['x']])
            ->assertSessionHas('aiError', ['target' => 'global', 'message' => 'La pregunta no existe o no es de respuesta abierta.']);

        \App\Models\Answer::where('question_id', $this->openIds[1])->delete();
        $this->post($this->route, ['questionId' => $this->openIds[1]])
            ->assertSessionHas('aiError', ['target' => $this->openIds[1], 'message' => 'Esta pregunta todavía no tiene respuestas entregadas.']);
        Queue::assertNothingPushed();
    }

    public function test_queues_the_analysis_and_marks_it_pending(): void
    {
        Queue::fake();
        $this->actingAsAdmin($this->admin);
        $this->post($this->route, ['questionId' => $this->openIds[0]])
            ->assertRedirect()
            ->assertSessionMissing('aiError');

        Queue::assertPushed(AnalyzeOpenAnswers::class, fn (AnalyzeOpenAnswers $job) => $job->assessmentId === $this->assessment->id
            && $job->questionId === $this->openIds[0]
            && $job->adminId === $this->admin->id
            && $job->force === false
            && $job->uniqueId() === $this->assessment->id.':'.$this->openIds[0]);
        $this->assertSame([$this->openIds[0]], $this->props()['aiPending']);

        // A second click while it is pending does not queue it again.
        $this->post($this->route, ['questionId' => $this->openIds[0], 'regenerar' => true])
            ->assertSessionHas('aiError', ['target' => $this->openIds[0], 'message' => 'Ya hay un análisis en curso para esta pregunta. Espere a que termine.']);
        Queue::assertPushed(AnalyzeOpenAnswers::class, 1);

        // Global analysis is its own key.
        $this->post($this->route)->assertSessionMissing('aiError');
        Queue::assertPushed(AnalyzeOpenAnswers::class, fn ($job) => $job->questionId === null && $job->uniqueId() === $this->assessment->id.':global');
        $this->assertEqualsCanonicalizing([$this->openIds[0], 'global'], $this->props()['aiPending']);
    }

    public function test_the_job_calls_the_api_with_anonymized_answers_and_stores_a_valid_analysis(): void
    {
        $this->fakeApi();
        $this->actingAsAdmin($this->admin);
        // QUEUE_CONNECTION=sync in tests: the job runs inside the request.
        $this->post($this->route, ['questionId' => $this->openIds[0]])->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $content = $r->data()['messages'][0]['content'];

            return $r->header('x-api-key') === ['sk-ant-prueba']
                && $r->header('anthropic-version') === ['2023-06-01']
                && $r->data()['model'] === 'claude-opus-5-5'
                && str_contains($content, '[persona]') && str_contains($content, '[email]')
                && ! str_contains($content, 'Carolina') && ! str_contains($content, 'mateo@');
        });

        $row = AiAnalysis::sole();
        $this->assertSame($this->openIds[0], $row->question_id);
        $this->assertSame('claude-opus-5-5', $row->model);
        $this->assertTrue(AiStore::isValidResult($row->result));
        $this->assertSame(1, AuditLog::where('action', 'ia.analisis')->count());

        $props = $this->props();
        $this->assertSame([], $props['aiPending']);
        $this->assertSame($row->id, $props['analyses']->{$this->openIds[0]}['id']);
        $this->assertSame([], (array) $props['aiErrors']);
    }

    public function test_unchanged_answers_answer_from_the_saved_analysis_without_queueing(): void
    {
        $this->fakeApi();
        $this->actingAsAdmin($this->admin);
        $this->post($this->route, ['questionId' => $this->openIds[0]]);
        Queue::fake();

        $this->post($this->route, ['questionId' => $this->openIds[0]])
            ->assertRedirect()
            ->assertSessionHas('aiCached', $this->openIds[0]);
        Queue::assertNothingPushed();
        $this->assertSame([], $this->props()['aiPending']);

        // "Regenerar" queues it anyway.
        $this->post($this->route, ['questionId' => $this->openIds[0], 'regenerar' => true])->assertSessionMissing('aiCached');
        Queue::assertPushed(AnalyzeOpenAnswers::class, fn ($job) => $job->force === true);
    }

    public function test_regenerate_stores_a_new_row_through_the_job(): void
    {
        $this->fakeApi();
        $this->actingAsAdmin($this->admin);
        $this->post($this->route);
        $this->post($this->route, ['regenerar' => '1']);
        $this->assertSame(2, AiAnalysis::whereNull('question_id')->count());
        Http::assertSentCount(2);
    }

    public function test_a_failing_job_stores_the_error_for_that_key_and_clears_pending(): void
    {
        $this->stubApi([self::URL => Http::response(['type' => 'error', 'error' => ['message' => 'x']], 401)]);
        $this->actingAsAdmin($this->admin);
        $this->post($this->route, ['questionId' => $this->openIds[0]])->assertRedirect();

        $props = $this->props();
        $this->assertSame([], $props['aiPending']);
        $this->assertSame(
            [$this->openIds[0] => 'La API key de Anthropic no es válida o no tiene permisos. Revise ANTHROPIC_API_KEY.'],
            (array) $props['aiErrors']
        );
        $this->assertSame(0, AiAnalysis::count());

        // A new attempt clears the previous error.
        $this->fakeApi();
        $this->post($this->route, ['questionId' => $this->openIds[0]]);
        $this->assertSame([], (array) $this->props()['aiErrors']);
    }

    public function test_a_job_killed_by_the_worker_clears_pending_with_a_generic_error(): void
    {
        AiJobState::markPending($this->assessment->id, 'global');
        $job = new AnalyzeOpenAnswers($this->assessment->id, null, $this->admin->id, false, null);
        $job->failed(new \RuntimeException('timeout'));

        $props = $this->props();
        $this->assertSame([], $props['aiPending']);
        $this->assertSame(['global' => 'Ocurrió un error inesperado al analizar con IA.'], (array) $props['aiErrors']);
    }

    public function test_the_job_timeout_follows_ai_timeout_ms(): void
    {
        config(['assessments.anthropic.timeout_ms' => 60000]);
        $job = new AnalyzeOpenAnswers($this->assessment->id, null, $this->admin->id, false, null);
        $this->assertSame(1, $job->tries);
        $this->assertGreaterThan(60 * 3, $job->timeout);
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }

    public function test_request_errors_and_cached_notice_reach_the_reports_props(): void
    {
        $this->withSession([]);
        session()->flash('aiError', ['target' => 'global', 'message' => 'Todavía no hay respuestas abiertas entregadas.']);
        session()->flash('aiCached', $this->openIds[1]);
        $props = $this->props();
        $this->assertSame(['global' => 'Todavía no hay respuestas abiertas entregadas.'], (array) $props['aiErrors']);
        $this->assertSame($this->openIds[1], $props['aiCached']);
        $this->assertSame(['configured' => true, 'model' => 'claude-opus-5-5'], $props['ai']);
    }
}
