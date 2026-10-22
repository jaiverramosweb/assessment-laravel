<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\Format;
use App\Services\Reports\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Reports\Scenarios;
use Tests\TestCase;

/** Port of src/lib/server/services/reports.spec.ts. */
class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = Scenarios::report()->id;
    }

    public function test_summarizes_participation_and_average_alignment(): void
    {
        $s = Reports::build($this->id)['summary'];
        $this->assertSame([5, 1, 3], [$s['invited'], $s['inProgress'], $s['submitted']]);
        $this->assertEqualsWithDelta(3 / 5, $s['participationRate'], 1e-9);
        // P1 2/3, P2 2/3, P4 1/2 → 0.6111
        $this->assertEqualsWithDelta((2 / 3 + 2 / 3 + 1 / 2) / 3, $s['averageAlignment'], 1e-9);
    }

    public function test_counts_per_option_only_final_submissions_and_marks_the_expected_one(): void
    {
        [$p1, $p2, $p4] = Reports::build($this->id)['choice'];
        $this->assertSame([['A', 2, true], ['B', 1, false]], array_map(fn ($o) => [$o['text'], $o['count'], $o['isExpected']], $p1['options']));
        $this->assertSame([1, 3, 2, 0], [$p1['number'], $p1['respondents'], $p1['aligned'], $p1['omitted']]);
        $this->assertEqualsWithDelta(2 / 3, $p1['alignmentRate'], 1e-9);
        $this->assertSame([0, 1, 2], array_column($p2['options'], 'count'));
        // P4 is optional: Luis did not answer it.
        $this->assertSame([4, 2, 1, 1], [$p4['number'], $p4['respondents'], $p4['omitted'], $p4['aligned']]);
        $this->assertSame(0.5, $p4['options'][0]['rate']);
    }

    public function test_lists_the_full_open_answers_with_their_author_without_drafts(): void
    {
        $open = Reports::build($this->id)['open'];
        $this->assertCount(1, $open);
        $this->assertSame(3, $open[0]['number']);
        $this->assertSame(
            [['Ana', 'Texto de Ana'], ['Eva', 'Texto de Eva'], ['Luis', 'Texto de Luis']],
            array_map(fn ($r) => [$r['participantName'], $r['text']], $open[0]['responses']),
        );
    }

    public function test_orders_the_most_and_least_aligned_questions(): void
    {
        $s = Reports::build($this->id)['summary'];
        $this->assertSame([1], array_column($s['mostAligned'], 'number'));
        $this->assertSame([4], array_column($s['leastAligned'], 'number'));
    }

    public function test_filters_by_area(): void
    {
        $r = Reports::build($this->id, ['area' => 'TI']);
        $this->assertSame([2, 2, 0], [$r['summary']['invited'], $r['summary']['submitted'], $r['summary']['inProgress']]);
        $this->assertEquals(1, $r['choice'][0]['alignmentRate']);
        $this->assertEquals(0.5, $r['choice'][1]['alignmentRate']);
        $this->assertCount(2, $r['open'][0]['responses']);
    }

    public function test_without_submissions_rates_are_empty_not_zero(): void
    {
        $r = Reports::build($this->id, ['area' => 'Inexistente']);
        $this->assertNull($r['summary']['participationRate']);
        $this->assertNull($r['summary']['averageAlignment']);
        $this->assertNull($r['choice'][0]['alignmentRate']);
        $this->assertSame('—', Format::percent($r['choice'][0]['alignmentRate']));
    }

    public function test_formats_percentages_in_spanish(): void
    {
        $this->assertSame('67%', Format::percent(2 / 3));
        $this->assertSame('61,1%', Format::percent(0.6111, 1));
        $this->assertSame('+12 pp', Format::points(0.12));
        $this->assertSame('−5 pp', Format::points(-0.05));
        $this->assertSame('0 pp', Format::points(0.001));
        $this->assertSame('—', Format::points(null));
    }

    public function test_answers_by_participant_hold_the_option_text_or_the_open_answer(): void
    {
        $r = Reports::build($this->id);
        $ana = collect($r['participants'])->firstWhere('name', 'Ana');
        $choice = array_column($r['choice'], 'id', 'number');
        $answers = $r['answersByParticipant'][$ana['id']];
        $this->assertSame(['A', 'C', 'X'], [$answers[$choice[1]], $answers[$choice[2]], $answers[$choice[4]]]);
        $this->assertSame('Texto de Ana', $answers[$r['open'][0]['id']]);
        $teo = collect($r['participants'])->firstWhere('name', 'Teo');
        $this->assertArrayNotHasKey($teo['id'], $r['answersByParticipant']);
    }

    public function test_participant_scores_count_expected_over_answered(): void
    {
        $scores = Reports::participantScores($this->id);
        $byName = collect($this->participants())->mapWithKeys(fn ($p) => [$p['name'] => $scores[$p['id']]['rate'] ?? null])->all();
        // Ana 3/3, Luis 1/2, Eva 1/3; Teo did not submit.
        $this->assertEquals(1, $byName['Ana']);
        $this->assertEqualsWithDelta(0.5, $byName['Luis'], 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $byName['Eva'], 1e-9);
        $this->assertNull($byName['Teo']);
    }

    private function participants(): array
    {
        return Reports::build($this->id)['participants'];
    }
}
