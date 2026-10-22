<?php

namespace Tests\Feature\Reports;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\Reports\Scenarios;
use Tests\TestCase;

/** /admin/assessments/{id}/reportes (+ /excel, /pdf). */
class ReportRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Assessment $a;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze(1793966400000); // 2026-11-06T12:00:00Z
        $this->a = Scenarios::report();
        $this->base = "/admin/assessments/{$this->a->id}/reportes";
    }

    public function test_requires_an_admin_session(): void
    {
        $this->get($this->base)->assertRedirect();
        $this->get($this->base.'/excel')->assertRedirect();
        $this->get($this->base.'/pdf')->assertRedirect();
        $this->assertSame(0, AuditLog::where('action', 'reporte.exportado')->count());
    }

    public function test_unknown_assessment_is_404(): void
    {
        $this->actingAsAdmin();
        $id = '00000000-0000-0000-0000-000000000000';
        $this->get("/admin/assessments/{$id}/reportes")->assertNotFound();
        $this->get("/admin/assessments/{$id}/reportes/excel")->assertNotFound();
        $this->get("/admin/assessments/{$id}/reportes/pdf")->assertNotFound();
    }

    public function test_page_has_the_report_filters_and_ai_props(): void
    {
        $this->actingAsAdmin();
        $this->get($this->base)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assessments/Reports')
                ->where('context.assessment.id', $this->a->id)
                ->where('report.summary.invited', 5)
                ->where('report.summary.submitted', 3)
                ->where('report.filters', ['area' => null, 'cargo' => null])
                ->has('report.choice', 3)
                ->where('report.choice.0.options.0.isExpected', true)
                ->has('report.open.0.responses', 3)
                ->has('report.participants', 5)
                ->where('report.answersByParticipant', [])
                ->where('areas', ['Finanzas', 'TI'])
                ->where('cargos', [])
                ->where('query', '')
                ->where('ai.configured', false)
                ->has('analyses')
                ->where('aiPending', []));
    }

    public function test_only_filter_values_present_among_participants_are_accepted(): void
    {
        $this->actingAsAdmin();
        $this->get($this->base.'?area=TI&cargo=Inventado')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.filters', ['area' => 'TI', 'cargo' => null])
                ->where('report.summary.invited', 2)
                ->where('query', '?area=TI&cargo=Inventado'));

        $this->get($this->base.'?area=Otra')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.filters', ['area' => null, 'cargo' => null])
                ->where('report.summary.invited', 5));
    }

    public function test_excel_download_respects_filters_and_is_audited(): void
    {
        $admin = $this->actingAsAdmin();
        $response = $this->get($this->base.'/excel?area=TI')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="informe-reporte-2026-11-06.xlsx"');

        $wb = ExportTest::load($response->getContent());
        // Only the two TI participants (+ header) before the question reference.
        $this->assertSame(['Luis', 'Ana'], [
            $wb->getSheetByName('Por participante')->getCell('A3')->getValue(),
            $wb->getSheetByName('Por participante')->getCell('A2')->getValue(),
        ]);
        $this->assertSame('Área: TI', $wb->getSheetByName('Resumen')->getCell('B5')->getValue());

        $log = AuditLog::where('action', 'reporte.exportado')->sole();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame(['assessment', $this->a->id], [$log->entity_type, $log->entity_id]);
        $this->assertSame(['formato' => 'Excel', 'filtros' => 'Área: TI'], $log->metadata);
    }

    public function test_pdf_download_is_audited(): void
    {
        $this->actingAsAdmin();
        $response = $this->get($this->base.'/pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="informe-reporte-2026-11-06.pdf"');
        $this->assertSame('%PDF-', substr($response->getContent(), 0, 5));
        $this->assertSame(
            ['formato' => 'PDF', 'filtros' => 'Todos los participantes'],
            AuditLog::where('action', 'reporte.exportado')->sole()->metadata,
        );
    }
}
