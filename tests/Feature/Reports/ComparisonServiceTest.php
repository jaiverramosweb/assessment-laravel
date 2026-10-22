<?php

namespace Tests\Feature\Reports;

use App\Models\Assessment;
use App\Services\Assessments\Series;
use App\Services\Reports\Comparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Reports\Scenarios;
use Tests\TestCase;

/** Port of the "comparativo entre mediciones" cases of src/lib/server/services/series.spec.ts. */
class ComparisonServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paired_only_counts_people_who_answered_both(): void
    {
        ['pre' => $pre] = Scenarios::series();
        $c = Comparison::build($pre);
        $this->assertSame([2, 2], array_column($c['measurements'], 'submitted'));
        $this->assertSame(2, $c['summary']['paired']);
        $this->assertEqualsWithDelta(0.25, $c['summary']['firstScore'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $c['summary']['lastScore'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $c['summary']['delta'], 1e-9);

        [$p1, $p2] = $c['choice'];
        $this->assertEquals([0.5, 1], $p1['rates']);
        $this->assertSame(['improved' => 1, 'keptRight' => 1, 'worsened' => 0, 'keptWrong' => 0], $p1['transitions']);
        $this->assertEquals([0, 0.5], $p2['rates']);
        $this->assertSame(['improved' => 1, 'keptRight' => 0, 'worsened' => 0, 'keptWrong' => 1], $p2['transitions']);
        $this->assertEquals([[0, 0.5], [0.5, 0.5], [0.5, 0]], array_column($p2['options'], 'rates'));

        $this->assertEquals(
            [['Ana', [0.5, 1], 0.5], ['Eva', [0, 0.5], 0.5]],
            array_map(fn ($p) => [$p['name'], $p['scores'], $p['delta']], $c['people']),
        );
        $this->assertEquals(
            [['Finanzas', [0, 0.5]], ['TI', [0.5, 1]]],
            array_map(fn ($g) => [$g['name'], $g['scores']], $c['byArea']),
        );
        $this->assertSame([['email' => 'ana@x.co', 'name' => 'Ana', 'texts' => ['Poco', 'Mucho']]], $c['open'][0]['responses']);
    }

    public function test_all_mode_uses_every_submission_of_each_measurement(): void
    {
        ['pre' => $pre] = Scenarios::series();
        $c = Comparison::build($pre, 'todos');
        $this->assertSame([3, 2], array_column($c['measurements'], 'submitted'));
        $this->assertEqualsWithDelta(0.5, $c['summary']['firstScore'], 1e-9);
        $this->assertNull($c['choice'][0]['transitions']);
        $luis = collect($c['people'])->firstWhere('name', 'Luis');
        $this->assertEquals([1, null], $luis['scores']);
        $this->assertNull($luis['delta']);
    }

    public function test_measurements_can_be_chosen_and_drafts_are_left_out_by_default(): void
    {
        ['pre' => $pre, 'post' => $post] = Scenarios::series();
        $third = Series::createNextMeasurement($post->id, Scenarios::admin()->id);
        $c = Comparison::build($pre);
        $this->assertSame([1, 2, 3], array_column($c['series'], 'order'));
        $this->assertSame([$pre->id, $post->id], array_column($c['measurements'], 'id'));
        $solo = Comparison::build($pre, 'pareado', [$post->id, $third->id]);
        $this->assertSame([$post->id, $third->id], array_column($solo['measurements'], 'id'));
    }

    public function test_params_read_the_mode_and_ignore_foreign_ids(): void
    {
        $ids = ['11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222'];
        $this->assertSame(
            ['mode' => 'todos', 'selectedIds' => [$ids[1]]],
            Comparison::params('modo=todos&m='.$ids[1].'&m=otro', $ids),
        );
        $this->assertSame(['mode' => 'pareado', 'selectedIds' => $ids], Comparison::params('m='.$ids[0].'&m[]='.$ids[1], $ids));
        $this->assertSame(['mode' => 'pareado', 'selectedIds' => []], Comparison::params('', $ids));
    }

    public function test_single_assessment_has_no_series(): void
    {
        $this->assertCount(1, Comparison::members(Scenarios::assessment()));
        $this->assertInstanceOf(Assessment::class, Comparison::members(Scenarios::assessment())->first());
    }
}
