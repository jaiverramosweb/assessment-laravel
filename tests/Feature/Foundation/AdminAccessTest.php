<?php

namespace Tests\Feature\Foundation;

use App\Models\AdminUser;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'clave-segura-123';

    private Client $acme;

    private Client $globex;

    private Assessment $acmeAssessment;

    private Assessment $globexAssessment;

    private Assessment $noClientAssessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = Client::create(['name' => 'Acme']);
        $this->globex = Client::create(['name' => 'Globex']);
        $this->acmeAssessment = $this->createAssessment(['title' => 'Clima Acme', 'client_id' => $this->acme->id, 'status' => 'publicado']);
        $this->globexAssessment = $this->createAssessment(['title' => 'Clima Globex', 'client_id' => $this->globex->id, 'status' => 'publicado']);
        $this->noClientAssessment = $this->createAssessment(['title' => 'Sin cliente', 'status' => 'publicado']);
    }

    /** A report-only user limited to the given clients (null = every client). */
    private function reportViewer(?array $clientIds, string $email = 'lector@x.co'): AdminUser
    {
        $user = $this->createAdmin($email, self::PASSWORD);
        $user->update([
            'access' => 'limited',
            'permissions' => ['reports' => $clientIds === null ? ['scope' => 'all'] : ['scope' => 'clients', 'clientIds' => $clientIds]],
        ]);

        return $user->fresh();
    }

    private function createPayload(array $overrides = []): array
    {
        return [
            'name' => 'Lector',
            'email' => 'lector@x.co',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'access' => 'limited',
            'permissions' => ['reports'],
            'reportsScope' => 'clients',
            'reportsClientIds' => [$this->acme->id],
            ...$overrides,
        ];
    }

    public function test_existing_and_new_admins_default_to_full_access(): void
    {
        $admin = $this->createAdmin();

        $this->assertSame('full', $admin->fresh()->access);
    }

    public function test_create_report_only_user_scoped_to_clients(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/usuarios', $this->createPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $user = AdminUser::where('email', 'lector@x.co')->first();
        $this->assertSame('limited', $user->access);
        $this->assertSame(['reports' => ['scope' => 'clients', 'clientIds' => [$this->acme->id]]], $user->permissions);
        $log = AuditLog::where('action', 'admin.creado')->latest('created_at')->first();
        $this->assertSame('limited', $log->metadata['acceso']);
    }

    public function test_create_report_only_user_for_all_clients(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/usuarios', $this->createPayload(['reportsScope' => 'all', 'reportsClientIds' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['reports' => ['scope' => 'all']], AdminUser::where('email', 'lector@x.co')->first()->permissions);
    }

    public function test_create_full_access_user_ignores_permissions(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/usuarios', $this->createPayload(['access' => 'full']))->assertSessionHasNoErrors();

        $user = AdminUser::where('email', 'lector@x.co')->first();
        $this->assertSame('full', $user->access);
        $this->assertNull($user->permissions);
    }

    public function test_limited_access_validation_errors(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/usuarios', $this->createPayload(['permissions' => []]))
            ->assertSessionHasErrors(['permissions' => 'Elija al menos un permiso.']);
        $this->post('/admin/usuarios', $this->createPayload(['reportsClientIds' => []]))
            ->assertSessionHasErrors(['reportsClientIds' => 'Elija al menos un cliente.']);
        $this->post('/admin/usuarios', $this->createPayload(['reportsClientIds' => ['00000000-0000-0000-0000-000000000000']]))
            ->assertSessionHasErrors(['reportsClientIds' => 'Uno de los clientes elegidos no existe.']);
        $this->post('/admin/usuarios', $this->createPayload(['access' => 'otro']))
            ->assertSessionHasErrors(['access' => 'Elija un nivel de acceso.']);

        $this->assertFalse(AdminUser::where('email', 'lector@x.co')->exists());
    }

    public function test_change_access_of_another_user(): void
    {
        $this->actingAsAdmin();
        $other = $this->createAdmin('david@x.co');

        $this->post("/admin/usuarios/{$other->id}/acceso", [
            'access' => 'limited',
            'permissions' => ['reports'],
            'reportsScope' => 'clients',
            'reportsClientIds' => [$this->globex->id],
        ])->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();

        $other->refresh();
        $this->assertSame('limited', $other->access);
        $this->assertSame(['reports' => ['scope' => 'clients', 'clientIds' => [$this->globex->id]]], $other->permissions);
        $this->assertTrue(AuditLog::where('action', 'admin.acceso_cambiado')->where('entity_id', $other->id)->exists());

        $this->post("/admin/usuarios/{$other->id}/acceso", ['access' => 'full'])->assertSessionHasNoErrors();
        $other->refresh();
        $this->assertSame('full', $other->access);
        $this->assertNull($other->permissions);
    }

    public function test_cannot_change_own_access(): void
    {
        $me = $this->actingAsAdmin();

        $this->post("/admin/usuarios/{$me->id}/acceso", ['access' => 'limited', 'permissions' => ['reports'], 'reportsScope' => 'all'])
            ->assertSessionHas('error', 'No puede cambiar su propio nivel de acceso.');

        $this->assertSame('full', $me->fresh()->access);
    }

    public function test_users_index_lists_access_and_client_options(): void
    {
        $this->actingAsAdmin();
        $this->reportViewer([$this->acme->id]);

        $this->get('/admin/usuarios')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.0.email', 'ana@empresa.com')
                ->where('users.0.access.full', true)
                ->where('users.1.email', 'lector@x.co')
                ->where('users.1.access.full', false)
                ->where('users.1.access.reports.all', false)
                ->where('users.1.access.reports.clientIds', [$this->acme->id])
                ->has('clients', 2));
    }

    public function test_limited_user_dashboard_only_lists_assessments_in_scope(): void
    {
        $this->actingAsAdmin($this->reportViewer([$this->acme->id]));

        $this->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assessments/Index')
                ->has('assessments', 1)
                ->where('assessments.0.id', $this->acmeAssessment->id)
                ->has('clients', 1)
                ->where('clients.0.id', $this->acme->id)
                ->where('kpis.published', 1)
                ->where('admin.access.full', false));
    }

    public function test_limited_user_with_all_clients_sees_every_assessment(): void
    {
        $this->actingAsAdmin($this->reportViewer(null));

        $this->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page->has('assessments', 3)->has('clients', 2));
        $this->get("/admin/assessments/{$this->noClientAssessment->id}/reportes")->assertOk();
        $this->get("/admin/clientes/{$this->globex->id}/informe")->assertOk();
    }

    public function test_limited_user_sees_reports_of_clients_in_scope_only(): void
    {
        $this->actingAsAdmin($this->reportViewer([$this->acme->id]));
        $acme = $this->acmeAssessment->id;
        $globex = $this->globexAssessment->id;

        $this->get("/admin/assessments/{$acme}/reportes")->assertOk();
        $this->get("/admin/assessments/{$acme}/reportes/excel")->assertOk();
        $this->get("/admin/assessments/{$acme}/mediciones")->assertOk();
        $this->get("/admin/clientes/{$this->acme->id}/informe")->assertOk();

        $this->get("/admin/assessments/{$globex}/reportes")->assertForbidden();
        $this->get("/admin/assessments/{$globex}/reportes/excel")->assertForbidden();
        $this->get("/admin/assessments/{$globex}/mediciones")->assertForbidden();
        $this->get("/admin/assessments/{$this->noClientAssessment->id}/reportes")->assertForbidden();
        $this->get("/admin/clientes/{$this->globex->id}/informe")->assertForbidden();
    }

    public function test_limited_user_cannot_open_anything_else(): void
    {
        $this->actingAsAdmin($this->reportViewer(null));
        $a = $this->acmeAssessment->id;

        foreach ([
            "/admin/assessments/{$a}", "/admin/assessments/{$a}/preguntas", "/admin/assessments/{$a}/participantes",
            "/admin/assessments/{$a}/invitaciones", '/admin/assessments/nuevo', '/admin/clientes',
            "/admin/clientes/{$this->acme->id}", '/admin/usuarios', '/admin/auditoria',
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->post("/admin/assessments/{$a}/eliminar")->assertForbidden();
        $this->post("/admin/assessments/{$a}/ia/analizar")->assertForbidden();
        $this->post('/admin/usuarios', $this->createPayload(['email' => 'nuevo@x.co', 'access' => 'full']))->assertForbidden();
        $this->post('/admin/clientes', ['name' => 'Nuevo'])->assertForbidden();

        $this->assertNotNull(Assessment::find($a));
        $this->assertFalse(AdminUser::where('email', 'nuevo@x.co')->exists());
        $this->assertSame(2, Client::count());
    }

    public function test_limited_user_keeps_my_account(): void
    {
        $this->actingAsAdmin($this->reportViewer(null));

        $this->get('/admin/cuenta')->assertOk();
    }

    public function test_limited_user_without_valid_permissions_sees_nothing(): void
    {
        $user = $this->reportViewer(null);
        $user->update(['permissions' => null]);
        $this->actingAsAdmin($user->fresh());

        $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('assessments', 0));
        $this->get("/admin/assessments/{$this->acmeAssessment->id}/reportes")->assertForbidden();
    }

    public function test_only_report_and_account_routes_are_open_to_limited_admins(): void
    {
        $open = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin/') && $route->uri() !== 'admin') {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            if (! in_array('admin', $middleware, true)) {
                continue;
            }
            if (! in_array('admin.full', $route->excludedMiddleware(), true)) {
                continue;
            }
            $open[] = implode('|', $route->methods()).' '.$route->uri();
            $this->assertTrue(
                $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/cuenta')
                    || in_array('admin.reports:assessment', $middleware, true) || in_array('admin.reports:client', $middleware, true),
                "{$route->uri()} skips admin.full without a report check"
            );
        }

        sort($open);
        $this->assertSame([
            'GET|HEAD admin',
            'GET|HEAD admin/assessments/{id}/mediciones',
            'GET|HEAD admin/assessments/{id}/mediciones/excel',
            'GET|HEAD admin/assessments/{id}/mediciones/pdf',
            'GET|HEAD admin/assessments/{id}/reportes',
            'GET|HEAD admin/assessments/{id}/reportes/excel',
            'GET|HEAD admin/assessments/{id}/reportes/pdf',
            'GET|HEAD admin/clientes/{id}/informe',
            'GET|HEAD admin/clientes/{id}/informe/excel',
            'GET|HEAD admin/clientes/{id}/informe/pdf',
            'GET|HEAD admin/cuenta',
            'POST admin/cuenta/password',
        ], $open);
    }

    public function test_full_admin_keeps_access_everywhere(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page->has('assessments', 3)->where('admin.access.full', true));
        $this->get("/admin/assessments/{$this->globexAssessment->id}")->assertOk();
        $this->get("/admin/assessments/{$this->globexAssessment->id}/reportes")->assertOk();
        $this->get('/admin/usuarios')->assertOk();
    }
}
