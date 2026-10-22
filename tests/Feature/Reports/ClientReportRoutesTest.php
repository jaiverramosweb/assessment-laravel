<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\Client;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\Reports\Scenarios;
use Tests\TestCase;

/** /admin/clientes/{id}/informe (+ /excel, /pdf). */
class ClientReportRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_an_admin_session(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $this->get("/admin/clientes/{$client->id}/informe")->assertRedirect();
        $this->get("/admin/clientes/{$client->id}/informe/pdf")->assertRedirect();
    }

    public function test_unknown_client_is_404(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/clientes/00000000-0000-0000-0000-000000000000/informe')->assertNotFound();
        $this->get('/admin/clientes/00000000-0000-0000-0000-000000000000/informe/excel')->assertNotFound();
        $this->get('/admin/clientes/no-es-uuid/informe')->assertNotFound();
    }

    public function test_page_has_the_client_and_its_report(): void
    {
        $this->actingAsAdmin();
        ['client' => $client, 'pre' => $pre] = Scenarios::client();
        $this->get("/admin/clientes/{$client->id}/informe")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Clients/Report')
                ->where('client', ['id' => $client->id, 'name' => 'Acme', 'logoUrl' => null])
                ->where('report.summary.assessments', 2)
                ->where('report.summary.people', 3)
                ->where('report.assessments.0.title', 'Antes')
                ->where('report.assessments.0.status', 'publicado')
                ->where('report.byArea.0.name', 'TI')
                ->where("report.byArea.0.byAssessment.{$pre->id}", 0.75));
    }

    public function test_downloads_are_audited_with_the_client_file_name(): void
    {
        Clock::freeze(1793966400000);
        $admin = $this->actingAsAdmin();
        ['client' => $client] = Scenarios::client();
        $base = "/admin/clientes/{$client->id}/informe";

        $excel = $this->get("{$base}/excel")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="informe-general-acme-2026-11-06.xlsx"');
        $this->assertSame('Antes', ExportTest::load($excel->getContent())->getSheetByName('Assessments')->getCell('A2')->getValue());

        $pdf = $this->get("{$base}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="informe-general-acme-2026-11-06.pdf"');
        $this->assertSame('%PDF-', substr($pdf->getContent(), 0, 5));

        $logs = AuditLog::where('action', 'reporte.exportado')->orderBy('id')->get();
        $this->assertSame(['cliente', $client->id, $admin->id], [$logs[0]->entity_type, $logs[0]->entity_id, $logs[0]->admin_id]);
        $this->assertSame(['formato' => 'Excel', 'informe' => 'general del cliente'], $logs[0]->metadata);
        $this->assertSame(['formato' => 'PDF', 'informe' => 'general del cliente'], $logs[1]->metadata);
    }
}
