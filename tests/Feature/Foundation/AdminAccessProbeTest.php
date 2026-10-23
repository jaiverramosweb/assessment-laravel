<?php

namespace Tests\Feature\Foundation;

use App\Models\AdminUser;
use App\Models\Assessment;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Adversarial probes for limited (report-only) admins: default deny on the server. */
class AdminAccessProbeTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Client $globex;

    private Assessment $acmeA;

    private Assessment $globexA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = Client::create(['name' => 'Acme']);
        $this->globex = Client::create(['name' => 'Globex']);
        $this->acmeA = $this->createAssessment(['title' => 'Clima Acme', 'client_id' => $this->acme->id, 'status' => 'publicado']);
        $this->globexA = $this->createAssessment(['title' => 'Clima Globex SECRETO', 'client_id' => $this->globex->id, 'status' => 'publicado']);
    }

    private function limited(?array $permissions, string $email = 'lector@x.co'): AdminUser
    {
        $u = $this->createAdmin($email);
        DB::table('admin_users')->where('id', $u->id)->update([
            'access' => 'limited',
            'permissions' => $permissions === null ? null : json_encode($permissions),
        ]);

        return $u->fresh();
    }

    private function acmeOnly(): array
    {
        return ['reports' => ['scope' => 'clients', 'clientIds' => [$this->acme->id]]];
    }

    public function test_every_other_admin_route_is_forbidden_for_every_method(): void
    {
        $viewer = $this->limited(['reports' => ['scope' => 'all']]);
        $other = $this->createAdmin('otro@x.co');
        $this->actingAsAdmin($viewer);
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri !== 'admin' && ! str_starts_with($uri, 'admin/')) {
                continue;
            }
            if (in_array($uri, ['admin', 'admin/login', 'admin/logout', 'admin/cuenta', 'admin/cuenta/password'], true)
                || str_contains($uri, '/reportes') || str_contains($uri, '/mediciones') || str_contains($uri, '/informe')) {
                continue;
            }
            $id = str_starts_with($uri, 'admin/clientes/') ? $this->acme->id : $this->acmeA->id;
            $url = '/'.strtr($uri, ['{id}' => $id, '{userId}' => $other->id, '{participantId}' => '00000000-0000-0000-0000-000000000000']);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if ($method === 'GET' && $uri === 'admin/clientes/{id}/logo') {
                    continue; // Opened to report viewers (scope checked below).
                }
                // call() would drop the session cookie set with withUnencryptedCookie.
                $this->assertSame(403, $this->{strtolower($method)}($url)->status(), "{$method} {$url}");
                $checked++;
            }
        }
        $this->assertGreaterThan(40, $checked);
        $this->assertSame(2, Assessment::count());
        $this->assertSame(2, Client::count());
        $this->assertSame('full', $other->fresh()->access);
        $this->assertNull($other->fresh()->disabled_at);
    }

    public function test_limited_user_cannot_change_any_access_including_own(): void
    {
        $viewer = $this->limited($this->acmeOnly());
        $this->actingAsAdmin($viewer);

        $this->post("/admin/usuarios/{$viewer->id}/acceso", ['access' => 'full'])->assertForbidden();
        $this->assertSame('limited', $viewer->fresh()->access);
    }

    public function test_malformed_or_unknown_stored_access_is_denied(): void
    {
        $cases = [
            ['limited', '{not json'], ['limited', '"reports"'],
            ['limited', json_encode(['reports' => 'all'])],
            ['limited', json_encode(['reports' => ['scope' => 'clients', 'clientIds' => $this->acme->id]])],
            ['limited', json_encode(['reports' => ['scope' => 'ALL']])],
            ['FULL', null], ['', null], ['admin', null],
        ];
        foreach ($cases as $i => [$access, $perm]) {
            $u = $this->createAdmin("m{$i}@x.co");
            DB::table('admin_users')->where('id', $u->id)->update(['access' => $access, 'permissions' => $perm]);
            $this->actingAsAdmin($u->fresh());
            $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('assessments', 0)->has('clients', 0));
            $this->get("/admin/assessments/{$this->acmeA->id}/reportes")->assertForbidden();
            $this->get("/admin/clientes/{$this->acme->id}/informe")->assertForbidden();
            $this->get("/admin/assessments/{$this->acmeA->id}")->assertForbidden();
        }
    }

    public function test_non_uuid_and_missing_ids_do_not_bypass_scope(): void
    {
        $this->actingAsAdmin($this->limited($this->acmeOnly()));

        $this->get('/admin/assessments/no-es-uuid/reportes')->assertNotFound();
        $this->get('/admin/assessments/00000000-0000-0000-0000-000000000000/mediciones/pdf')->assertNotFound();
        $this->get('/admin/clientes/no-es-uuid/informe')->assertForbidden();
        $this->assertContains($this->get('/admin/assessments/'.strtoupper($this->globexA->id).'/reportes')->status(), [403, 404]);
    }

    public function test_dashboard_client_filter_cannot_select_out_of_scope_client(): void
    {
        $this->actingAsAdmin($this->limited($this->acmeOnly()));

        $this->get("/admin?cliente={$this->globex->id}&archivados=1")->assertOk()->assertDontSee('Clima Globex SECRETO')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('clientId', '')->has('assessments', 1)->has('clients', 1)->where('kpis.published', 1));
    }

    public function test_series_members_of_other_clients_stay_hidden(): void
    {
        $third = $this->createAssessment(['title' => 'Clima Acme 2', 'client_id' => $this->acme->id, 'status' => 'publicado']);
        $this->acmeA->update(['series_id' => $this->acmeA->id, 'series_order' => 1]);
        $this->globexA->update(['series_id' => $this->acmeA->id, 'series_order' => 2]);
        $third->update(['series_id' => $this->acmeA->id, 'series_order' => 3]);
        $this->actingAsAdmin($this->limited($this->acmeOnly()));

        $this->get("/admin/assessments/{$this->acmeA->id}/mediciones")->assertOk()->assertDontSee('Clima Globex SECRETO')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('context.series', 2)->has('comparison.series', 2));
        $this->get("/admin/assessments/{$this->acmeA->id}/reportes")->assertOk()->assertDontSee('Clima Globex SECRETO')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('context.series', 2));
        $this->get("/admin/assessments/{$this->acmeA->id}/mediciones/excel")->assertOk();
    }

    public function test_full_admin_still_sees_every_series_member(): void
    {
        $this->acmeA->update(['series_id' => $this->acmeA->id, 'series_order' => 1]);
        $this->globexA->update(['series_id' => $this->acmeA->id, 'series_order' => 2]);
        $this->actingAsAdmin();

        $this->get("/admin/assessments/{$this->acmeA->id}/mediciones")
            ->assertInertia(fn (AssertableInertia $p) => $p->has('context.series', 2)->has('comparison.series', 2));
    }

    public function test_limited_user_can_load_logo_of_client_in_scope_only(): void
    {
        $this->acme->update(['logo_mime' => 'image/png', 'logo_updated_at' => 1]);
        $this->actingAsAdmin($this->limited($this->acmeOnly()));

        $this->get("/admin/clientes/{$this->acme->id}/informe")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('client.logoUrl', "/admin/clientes/{$this->acme->id}/logo?v=1"));
        $this->assertNotSame(403, $this->get("/admin/clientes/{$this->acme->id}/logo?v=1")->status());
        $this->get("/admin/clientes/{$this->globex->id}/logo")->assertForbidden();
    }

    public function test_access_change_applies_on_next_request(): void
    {
        $viewer = $this->limited(['reports' => ['scope' => 'all']]);
        $this->actingAsAdmin($viewer);
        $this->get("/admin/assessments/{$this->globexA->id}/reportes")->assertOk();

        DB::table('admin_users')->where('id', $viewer->id)->update(['permissions' => json_encode($this->acmeOnly())]);
        $this->get("/admin/assessments/{$this->globexA->id}/reportes")->assertForbidden();
    }

    public function test_admin_create_command_still_creates_full_admins(): void
    {
        putenv('ADMIN_PASSWORD=clave-segura-123');
        try {
            $this->artisan('admin:create', ['--email' => 'cli@x.co', '--name' => 'Cli Admin'])->assertExitCode(0);
        } finally {
            putenv('ADMIN_PASSWORD');
        }
        $u = AdminUser::where('email', 'cli@x.co')->first();
        $this->assertSame('full', $u->access);
        $this->assertNull($u->permissions);
    }
}
