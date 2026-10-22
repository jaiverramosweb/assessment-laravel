<?php

namespace Tests\Feature\Assessments;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\QuestionOption;
use App\Models\Submission;
use App\Services\Assessments\Assessments;
use App\Services\Assessments\Questions;
use App\Services\Assessments\QuestionsLockedError;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Assessments\Builders;
use Tests\TestCase;

/** Port of src/lib/server/services/assessments.spec.ts. */
class AssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createAdmin('a@b.co')->id;
    }

    private function fresh(string $id): Assessment
    {
        return Assessment::findOrFail($id);
    }

    public function test_generates_random_12_char_slugs_without_ambiguous_characters(): void
    {
        $slugs = [];
        for ($i = 0; $i < 200; $i++) {
            $slugs[Assessments::generateSlug()] = true;
        }
        $this->assertCount(200, $slugs);
        foreach (array_keys($slugs) as $s) {
            $this->assertMatchesRegularExpression('/^[a-km-np-z2-9]{12}$/', $s);
        }
    }

    public function test_creates_audits_and_lists_with_counts(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        Questions::replace($a->id, Builders::questions(), $this->adminId);

        $rows = Assessments::listForDashboard();
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows[0]['id']);
        $this->assertSame(2, $rows[0]['questionCount']);
        $this->assertSame(0, $rows[0]['participantCount']);
        $this->assertSame(0, $rows[0]['submittedCount']);
        $this->assertSame(['assessment.creado', 'preguntas.editadas'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(['titulo' => 'Evaluación de seguridad'], AuditLog::first()->metadata);
    }

    public function test_publishing_requires_password_and_questions(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        $this->assertSame(
            ['Defina la contraseña de acceso.', 'Agregue al menos una pregunta.'],
            Assessments::publishProblems($a),
        );
        Assessments::setAccessPassword($a->id, 'clave-acceso', $this->adminId);
        Questions::replace($a->id, Builders::questions(), $this->adminId);
        $this->assertSame([], Assessments::publishProblems($this->fresh($a->id)));
        $this->assertMatchesRegularExpression('/^\$argon2id\$/', $this->fresh($a->id)->access_password_hash);
    }

    public function test_publish_problems_report_inverted_dates(): void
    {
        $a = $this->createAssessment(['starts_at' => 2000, 'ends_at' => 1000]);
        $this->assertContains('La fecha de finalización debe ser posterior al inicio.', Assessments::publishProblems($a));
    }

    public function test_archived_are_hidden_from_the_default_list(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        Assessments::setStatus($a, 'archivado', $this->adminId);
        $this->assertCount(0, Assessments::listForDashboard());
        $this->assertCount(1, Assessments::listForDashboard(includeArchived: true));
        $log = AuditLog::where('action', 'assessment.archivado')->first();
        $this->assertSame(['estado' => 'archivado', 'anterior' => 'borrador'], $log->metadata);
    }

    public function test_duplicates_questions_and_options_as_draft_without_password(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        Assessments::setAccessPassword($a->id, 'clave-acceso', $this->adminId);
        Questions::replace($a->id, Builders::questions(), $this->adminId);
        Assessments::setStatus($this->fresh($a->id), 'publicado', $this->adminId);

        $copy = Assessments::duplicate($a->id, $this->adminId);
        $this->assertSame('Copia de Evaluación de seguridad', $copy->title);
        $this->assertSame('borrador', $copy->status);
        $this->assertNull($copy->access_password_hash);
        $this->assertNotSame($a->slug, $copy->slug);
        $this->assertSame($a->id, $copy->duplicated_from_id);

        $qs = Questions::listForAdmin($copy->id);
        $this->assertSame(array_column(Builders::questions(), 'prompt'), array_column($qs, 'prompt'));
        $this->assertSame([true, false], array_column($qs[0]['options'], 'isCorrect'));
        $original = Questions::listForAdmin($a->id);
        $this->assertNotSame($original[0]['options'][0]['id'], $qs[0]['options'][0]['id']);
        $this->assertSame(1, AuditLog::where('action', 'assessment.duplicado')->count());
        $this->assertNull(Assessments::duplicate('00000000-0000-0000-0000-000000000000', $this->adminId));
    }

    public function test_replaces_questions_in_order_and_keeps_the_correct_option(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        Questions::replace($a->id, Builders::questions(), $this->adminId);
        Questions::replace($a->id, array_reverse(Builders::questions()), $this->adminId);
        $qs = Questions::listForAdmin($a->id);
        $this->assertSame([[1, 'abierta'], [2, 'opcion_unica']], array_map(fn ($q) => [$q['position'], $q['type']], $qs));
        // The previous version's options were deleted in cascade.
        $this->assertSame(2, QuestionOption::count());
        $log = AuditLog::where('action', 'preguntas.editadas')->orderByDesc('id')->first();
        $this->assertSame(['preguntas' => 2, 'seleccion' => 1, 'abiertas' => 1], $log->metadata);
    }

    public function test_blocks_editing_when_there_are_responses(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        Questions::replace($a->id, Builders::questions(), $this->adminId);
        $p = Participant::create(['assessment_id' => $a->id, 'name' => 'Ana', 'email' => 'ana@x.co']);
        Submission::create(['assessment_id' => $a->id, 'participant_id' => $p->id]);

        try {
            Questions::replace($a->id, [], $this->adminId);
            $this->fail('Expected QuestionsLockedError');
        } catch (QuestionsLockedError $e) {
            $this->assertSame(
                'Este assessment ya tiene respuestas: las preguntas no se pueden modificar. Duplíquelo para hacer cambios.',
                $e->getMessage(),
            );
        }
        $this->assertCount(2, Questions::listForAdmin($a->id));

        $copy = Assessments::duplicate($a->id, $this->adminId);
        Questions::replace($copy->id, [], $this->adminId);
        $this->assertSame(0, Submission::where('assessment_id', $copy->id)->count());
    }

    public function test_stores_the_password_encrypted_reveals_it_and_audits(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        Assessments::setAccessPassword($a->id, 'clave-visible-1', $this->adminId);
        $saved = $this->fresh($a->id);
        $this->assertStringStartsWith('v1:', $saved->access_password_enc);
        $this->assertStringNotContainsString('clave-visible-1', $saved->access_password_enc);
        $this->assertSame('clave-visible-1', Assessments::readAccessPassword($saved));

        $this->assertSame('clave-visible-1', Assessments::revealAccessPassword($saved, $this->adminId, '10.0.0.1'));
        $views = AuditLog::where('action', 'assessment.password_vista')->get();
        $this->assertCount(1, $views);
        $this->assertSame('10.0.0.1', $views[0]->ip);
        $this->assertStringNotContainsString('clave-visible-1', $views->toJson());
        $this->assertSame(1, AuditLog::where('action', 'assessment.password_cambiada')->count());
    }

    public function test_a_password_stored_only_as_hash_cannot_be_revealed(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        $this->assertNull(Assessments::readAccessPassword($a));
        $this->assertNull(Assessments::revealAccessPassword($a, $this->adminId));
        $this->assertSame(0, AuditLog::where('action', 'assessment.password_vista')->count());
    }

    public function test_delete_removes_everything_keeps_audit_and_other_assessments(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        $other = Assessments::create(Builders::input(), $this->adminId);
        Questions::replace($a->id, Builders::questions(), $this->adminId);
        Questions::replace($other->id, Builders::questions(), $this->adminId);
        $p = Participant::create(['assessment_id' => $a->id, 'name' => 'Ana', 'email' => 'ana@x.co']);
        Submission::create(['assessment_id' => $a->id, 'participant_id' => $p->id, 'submitted_at' => Clock::nowMs()]);

        $this->assertSame(['questions' => 1 + 1, 'participants' => 1, 'submitted' => 1], Assessments::deletionSummary($a->id));
        Assessments::delete($this->fresh($a->id), $this->adminId, '::1');

        $this->assertNull(Assessment::find($a->id));
        $this->assertSame(0, Participant::count());
        $this->assertSame(0, Submission::count());
        $this->assertCount(0, Questions::listForAdmin($a->id));
        $this->assertCount(2, Questions::listForAdmin($other->id));

        $log = AuditLog::where('action', 'assessment.eliminado')->first();
        $this->assertSame($a->id, $log->entity_id);
        $this->assertSame([
            'titulo' => 'Evaluación de seguridad',
            'estado' => 'borrador',
            'preguntas' => 2,
            'participantes' => 1,
            'entregas' => 1,
        ], $log->metadata);
    }

    public function test_average_alignment_counts_only_submitted_choice_answers(): void
    {
        $a = Assessments::create(Builders::input(), $this->adminId);
        Questions::replace($a->id, Builders::questions(), $this->adminId);
        $this->assertNull(Assessments::averageAlignment($a->id));

        $q = Questions::listForAdmin($a->id)[0];
        foreach ([['ana@x.co', 0, true], ['eva@x.co', 1, true], ['luis@x.co', 0, false]] as [$email, $opt, $submitted]) {
            $p = Participant::create(['assessment_id' => $a->id, 'name' => $email, 'email' => $email]);
            $s = Submission::create([
                'assessment_id' => $a->id, 'participant_id' => $p->id,
                'submitted_at' => $submitted ? Clock::nowMs() : null,
            ]);
            \App\Models\Answer::create(['submission_id' => $s->id, 'question_id' => $q['id'], 'option_id' => $q['options'][$opt]['id']]);
        }
        // Ana right, Eva wrong; Luis has not submitted.
        $this->assertEqualsWithDelta(0.5, Assessments::averageAlignment($a->id), 1e-9);
    }
}
