<?php

namespace Tests\Feature\Assessments;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Participant;
use App\Services\Assessments\Assessments;
use App\Services\Assessments\Questions;
use App\Services\Assessments\QuestionsLockedError;
use App\Services\Assessments\Series;
use App\Services\Shared\AssessmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Assessments\Builders;
use Tests\TestCase;

/** Port of the "series de mediciones" part of src/lib/server/services/series.spec.ts. */
class SeriesServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createAdmin('a@b.co')->id;
    }

    private function baseAssessment(?string $clientId = null): Assessment
    {
        $a = Assessments::create(Builders::input([
            'title' => 'Diagnóstico',
            'clientId' => $clientId ?? '',
            'description' => '',
            'startsAt' => '2026-01-10T08:00',
            'endsAt' => '2026-01-20T18:00',
        ]), $this->adminId);
        $opts = fn () => [
            ['text' => 'Sí', 'isCorrect' => true],
            ['text' => 'No', 'isCorrect' => false],
            ['text' => 'No sé', 'isCorrect' => false],
        ];
        Questions::replace($a->id, [
            ['type' => 'opcion_unica', 'prompt' => 'P1', 'required' => true, 'options' => $opts()],
            ['type' => 'opcion_unica', 'prompt' => 'P2', 'required' => true, 'options' => $opts()],
            ['type' => 'abierta', 'prompt' => '¿Qué aprendió?', 'required' => false],
        ], $this->adminId);
        foreach ([['Ana', 'ana@x.co', 'TI'], ['Eva', 'eva@x.co', 'Finanzas'], ['Luis', 'luis@x.co', 'TI']] as [$name, $email, $area]) {
            Participant::create([
                'assessment_id' => $a->id, 'name' => $name, 'email' => $email, 'area' => $area,
                'invited_at' => 1, 'extra' => ['sede' => 'Bogotá'],
            ]);
        }

        return $a;
    }

    public function test_next_measurement_copies_questions_client_and_participants_as_draft(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $pre = $this->baseAssessment($client->id);
        Assessments::setStatus(Assessment::find($pre->id), 'publicado', $this->adminId);

        $post = Series::createNextMeasurement($pre->id, $this->adminId);
        $this->assertSame('Diagnóstico · Medición 2', $post->title);
        $this->assertSame('borrador', $post->status);
        $this->assertSame($client->id, $post->client_id);
        $this->assertSame($pre->id, $post->series_id);
        $this->assertSame(2, $post->series_order);
        $this->assertSame('Responda con calma', $post->instructions);
        $this->assertNull($post->access_password_hash);
        $this->assertGreaterThan($pre->ends_at, $post->starts_at);
        $this->assertSame($pre->ends_at - $pre->starts_at, $post->ends_at - $post->starts_at);
        $fresh = Assessment::find($pre->id);
        $this->assertSame([$pre->id, 1], [$fresh->series_id, $fresh->series_order]);

        $qs = Questions::listForAdmin($post->id);
        $this->assertSame(['P1', 'P2', '¿Qué aprendió?'], array_column($qs, 'prompt'));
        $correct = array_values(array_filter($qs[0]['options'], fn ($o) => $o['isCorrect']));
        $this->assertSame('Sí', $correct[0]['text']);

        $people = Participant::where('assessment_id', $post->id)->orderBy('email')->get();
        $this->assertSame(['ana@x.co', 'eva@x.co', 'luis@x.co'], $people->pluck('email')->all());
        $this->assertTrue($people->every(fn ($p) => $p->invited_at === null));
        $this->assertSame(['sede' => 'Bogotá'], $people[0]->extra);

        $log = AuditLog::where('action', 'assessment.medicion_creada')->first();
        $this->assertSame([
            'titulo' => 'Diagnóstico · Medición 2', 'medicion' => 2, 'origen' => $pre->id,
            'preguntas' => 3, 'participantes' => 3,
        ], $log->metadata);

        // The third is numbered even when created from the first, and the title does not repeat.
        $third = Series::createNextMeasurement($pre->id, $this->adminId);
        $this->assertSame('Diagnóstico · Medición 3', $third->title);
        $this->assertSame([1, 2, 3], AssessmentContext::seriesMembers($third)->pluck('series_order')->all());
    }

    public function test_questions_cannot_be_modified_in_a_series(): void
    {
        $pre = $this->baseAssessment();
        $this->assertFalse(AssessmentContext::inSharedSeries($pre->id));
        $post = Series::createNextMeasurement($pre->id, $this->adminId);
        $this->assertTrue(AssessmentContext::inSharedSeries($pre->id));
        foreach ([$pre->id, $post->id] as $id) {
            try {
                Questions::replace($id, [], $this->adminId);
                $this->fail('Expected QuestionsLockedError');
            } catch (QuestionsLockedError $e) {
                $this->assertSame(AssessmentContext::LOCKED_BY_SERIES, $e->getMessage());
            }
        }
    }

    public function test_unknown_source_returns_null(): void
    {
        $this->assertNull(Series::createNextMeasurement('00000000-0000-0000-0000-000000000000', $this->adminId));
    }
}
