<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\Reports\Scenarios;
use Tests\TestCase;

/** /admin/assessments/{id}/mediciones (+ /excel, /pdf). */
class MeasurementRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_an_admin_session(): void
    {
        $a = Scenarios::assessment();
        $this->get("/admin/assessments/{$a->id}/mediciones")->assertRedirect();
        $this->get("/admin/assessments/{$a->id}/mediciones/excel")->assertRedirect();
    }

    public function test_without_other_measurements_there_is_no_comparison(): void
    {
        $this->actingAsAdmin();
        $a = Scenarios::assessment();
        $this->get("/admin/assessments/{$a->id}/mediciones")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assessments/Measurements')
                ->where('context.assessment.id', $a->id)
                ->where('comparison', null)
                ->where('query', ''));
        $this->get("/admin/assessments/{$a->id}/mediciones/excel")->assertNotFound();
        $this->get("/admin/assessments/{$a->id}/mediciones/pdf")->assertNotFound();
        $this->get('/admin/assessments/00000000-0000-0000-0000-000000000000/mediciones')->assertNotFound();
    }

    public function test_page_compares_the_series_with_mode_and_selection_from_the_url(): void
    {
        $this->actingAsAdmin();
        ['pre' => $pre, 'post' => $post] = Scenarios::series();
        $this->get("/admin/assessments/{$post->id}/mediciones")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assessments/Measurements')
                ->where('comparison.mode', 'pareado')
                ->where('comparison.summary.paired', 2)
                ->has('comparison.series', 2)
                ->has('comparison.measurements', 2)
                ->where('comparison.measurements.0.id', $pre->id)
                ->where('comparison.people.0.name', 'Ana'));

        $query = "?modo=todos&m={$post->id}";
        $this->get("/admin/assessments/{$post->id}/mediciones{$query}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('comparison.mode', 'todos')
                ->has('comparison.measurements', 1)
                ->where('comparison.measurements.0.id', $post->id)
                ->where('query', $query));
    }

    public function test_downloads_keep_mode_and_selection_and_are_audited(): void
    {
        Clock::freeze(1793966400000);
        $admin = $this->actingAsAdmin();
        ['pre' => $pre, 'post' => $post] = Scenarios::series();
        $base = "/admin/assessments/{$post->id}/mediciones";

        $response = $this->get("{$base}/excel?modo=todos&m={$pre->id}&m={$post->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="informe-comparativo-diagnostico-2026-11-06.xlsx"');
        $wb = ExportTest::load($response->getContent());
        // Title of the series without the " · Medición N" suffix; all submissions of each one.
        $this->assertSame('Comparativo · Diagnóstico', $wb->getSheetByName('Resumen')->getCell('A1')->getValue());
        $this->assertSame([3, 2], [
            $wb->getSheetByName('Resumen')->getCell('B7')->getValue(),
            $wb->getSheetByName('Resumen')->getCell('C7')->getValue(),
        ]);

        $this->get("{$base}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="informe-comparativo-diagnostico-2026-11-06.pdf"');

        $logs = AuditLog::where('action', 'reporte.exportado')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($admin->id, $logs[0]->admin_id);
        $this->assertSame(['assessment', $post->id], [$logs[0]->entity_type, $logs[0]->entity_id]);
        $this->assertSame(
            ['formato' => 'Excel', 'informe' => 'comparativo de mediciones', 'mediciones' => '1, 2'],
            $logs[0]->metadata,
        );
        $this->assertSame('PDF', $logs[1]->metadata['formato']);
    }
}
