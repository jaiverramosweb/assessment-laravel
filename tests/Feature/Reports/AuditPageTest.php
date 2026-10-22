<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Security\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** /admin/auditoria: latest 200 entries, newest first, with readable labels and details. */
class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_an_admin_session(): void
    {
        $this->get('/admin/auditoria')->assertRedirect();
    }

    public function test_lists_the_latest_entries_with_label_and_detail(): void
    {
        $admin = $this->actingAsAdmin();
        Audit::log($admin->id, 'assessment.creado', 'assessment', 'x', ['titulo' => 'Clima'], '10.0.0.1');
        Audit::log($admin->id, 'reporte.exportado', 'cliente', 'y', ['formato' => 'PDF', 'informe' => 'general del cliente'], '10.0.0.2');
        AuditLog::create(['action' => 'accion.antigua']);
        Audit::log(null, 'admin.login_fallido', null, null, null, null);

        $this->get('/admin/auditoria')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Audit/Index')
                ->where('limit', 200)
                ->has('entries', 4)
                ->where('entries.0.label', 'Intento de inicio de sesión fallido')
                ->where('entries.0.adminName', null)
                ->where('entries.0.detail', '')
                ->where('entries.0.ip', null)
                ->where('entries.1.label', 'accion.antigua')
                ->where('entries.2.label', 'Reporte exportado')
                ->where('entries.2.detail', 'formato: PDF · informe: general del cliente')
                ->where('entries.2.adminName', 'Ana Pérez')
                ->where('entries.2.adminEmail', 'ana@empresa.com')
                ->where('entries.2.entityType', 'cliente')
                ->where('entries.2.ip', '10.0.0.2')
                ->has('entries.2.createdAt')
                ->where('entries.3.detail', 'titulo: Clima'));
    }

    public function test_shows_at_most_200_entries(): void
    {
        $this->actingAsAdmin();
        for ($i = 0; $i < 205; $i++) {
            AuditLog::create(['action' => 'admin.login', 'metadata' => ['n' => $i]]);
        }
        $this->get('/admin/auditoria')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 200)
                ->where('entries.0.detail', 'n: 204'));
    }
}
