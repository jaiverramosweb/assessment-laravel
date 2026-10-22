<?php

namespace Tests\Feature\Reports;

use App\Services\Participants\Participants;
use App\Services\Reports\ClientReport;
use App\Services\Reports\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Reports\Scenarios;
use Tests\TestCase;

/** Port of src/lib/server/services/client-report.spec.ts (services part). */
class ClientReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenarios::client();
    }

    public function test_participant_score_counts_expected_answers_over_answered(): void
    {
        $scores = Reports::participantScores($this->s['pre']->id);
        $byName = collect(Participants::listParticipants($this->s['pre']->id))
            ->mapWithKeys(fn ($p) => [$p['name'] => $scores[$p['id']]['rate'] ?? null])->all();
        $this->assertEquals(['Ana' => 0.5, 'Eva' => 0, 'Luis' => 1], $byName);
    }

    public function test_lists_its_assessments_by_date_without_drafts_or_other_clients(): void
    {
        $r = ClientReport::build($this->s['client']->id);
        $this->assertSame(['Antes', 'Después'], array_column($r['assessments'], 'title'));
        $first = $r['assessments'][0];
        $this->assertSame([3, 3], [$first['participants'], $first['submitted']]);
        $this->assertEquals(1, $first['participationRate']);
        $this->assertEquals(0.5, $first['averageScore']);
        $this->assertSame(2, $r['assessments'][1]['submitted']);
        $this->assertEquals(0.75, $r['assessments'][1]['averageScore']);
        // Alignment of "Antes": P1 2/3, P2 1/3 → 0.5.
        $this->assertEqualsWithDelta(0.5, $first['averageAlignment'], 1e-9);
    }

    public function test_consolidates_people_submissions_and_score(): void
    {
        $s = ClientReport::build($this->s['client']->id)['summary'];
        $this->assertSame(
            ['assessments' => 2, 'withSubmissions' => 2, 'people' => 3, 'participants' => 6, 'submitted' => 5],
            array_intersect_key($s, array_flip(['assessments', 'withSubmissions', 'people', 'participants', 'submitted'])),
        );
        $this->assertEqualsWithDelta(5 / 6, $s['participationRate'], 1e-9);
        $this->assertEqualsWithDelta((0.5 + 0 + 1 + 1 + 0.5) / 5, $s['averageScore'], 1e-9);
    }

    public function test_groups_by_area_and_cargo_with_the_evolution_per_assessment(): void
    {
        $r = ClientReport::build($this->s['client']->id);
        $ti = collect($r['byArea'])->firstWhere('name', 'TI');
        $this->assertSame(3, $ti['submissions']);
        $this->assertEqualsWithDelta(0.75, $ti['byAssessment'][$this->s['pre']->id], 1e-9);
        $this->assertEquals(1, $ti['byAssessment'][$this->s['post']->id]);
        $this->assertSame(['TI', 'Finanzas'], array_column($r['byArea'], 'name'));
        // Without cargo: grouped as "Sin dato", last.
        $this->assertSame(['Analista', 'Sin dato'], array_column($r['byCargo'], 'name'));
    }
}
