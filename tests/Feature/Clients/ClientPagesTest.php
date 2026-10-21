<?php

namespace Tests\Feature\Clients;

use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Clients\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ClientPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_requires_a_session(): void
    {
        $this->get('/admin/clientes')->assertStatus(303)->assertRedirect('/admin/login?destino='.rawurlencode('/admin/clientes'));
    }

    public function test_the_list_shows_clients_with_their_count_and_logo_url(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        ClientService::setLogo($c->id, base64_decode(ClientServiceTest::PNG_BASE64), $admin->id);
        ClientService::setTheme($c->id, ['primary' => '#1a2b5c', 'accent' => '#c2410c'], $admin->id);
        $this->createAssessment(['client_id' => $c->id]);
        $this->createAssessment(['client_id' => $c->id]);
        ClientService::create('beta', $admin->id);

        $this->get('/admin/clientes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Clients/Index')
                ->has('clients', 2)
                ->where('clients.0.name', 'Acme')
                ->where('clients.0.assessmentCount', 2)
                ->where('clients.0.themePrimary', '#1a2b5c')
                ->where('clients.0.themeAccent', '#c2410c')
                ->where('clients.0.logoUrl', fn ($url) => (bool) preg_match('#^/admin/clientes/'.$c->id.'/logo\?v=\d+$#', $url))
                ->missing('clients.0.logoData')
                ->missing('clients.0.logo_data')
                ->where('clients.1.name', 'beta')
                ->where('clients.1.logoUrl', null));
    }

    public function test_creating_a_client_redirects_to_its_page(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->post('/admin/clientes', ['name' => '  Acme S.A. ']);

        $client = Client::firstOrFail();
        $this->assertSame('Acme S.A.', $client->name);
        $this->assertSame($admin->id, $client->created_by_id);
        $response->assertStatus(303)->assertRedirect("/admin/clientes/{$client->id}?creado=1");
        $this->assertTrue(AuditLog::where('action', 'cliente.creado')->where('entity_id', $client->id)->exists());
    }

    public function test_creating_rejects_invalid_and_duplicate_names(): void
    {
        $admin = $this->actingAsAdmin();
        ClientService::create('Acme S.A.', $admin->id);

        $this->from('/admin/clientes')->post('/admin/clientes', ['name' => 'acme s.a.'])
            ->assertStatus(303)
            ->assertRedirect('/admin/clientes')
            ->assertSessionHasErrors(['name' => 'Ya existe un cliente con ese nombre.'])
            ->assertSessionHasInput('name', 'acme s.a.');
        $this->from('/admin/clientes')->post('/admin/clientes', ['name' => 'A'])
            ->assertSessionHasErrors(['name' => 'El nombre debe tener al menos 2 caracteres.']);
        $this->assertSame(1, Client::count());
    }

    public function test_the_client_page_shows_its_data_and_assessments(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        $a = $this->createAssessment(['client_id' => $c->id, 'title' => 'Clima 2026', 'status' => 'archivado']);
        $p = $this->createParticipant($a);
        $this->createParticipant($a);
        \App\Models\Submission::create(['assessment_id' => $a->id, 'participant_id' => $p->id, 'submitted_at' => 1]);
        $this->createAssessment(['title' => 'De otro']);

        $this->get("/admin/clientes/{$c->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Clients/Show')
                ->where('client', [
                    'id' => $c->id, 'name' => 'Acme', 'themePrimary' => null, 'themeAccent' => null, 'logoUrl' => null,
                ])
                ->has('assessments', 1)
                ->where('assessments.0.title', 'Clima 2026')
                ->where('assessments.0.status', 'archivado')
                ->where('assessments.0.participantCount', 2)
                ->where('assessments.0.submittedCount', 1));
    }

    public function test_unknown_or_malformed_client_ids_are_not_found(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/clientes/no-es-uuid')->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('message', 'No encontrado'));
        $this->get('/admin/clientes/00000000-0000-0000-0000-000000000000')->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('message', 'Cliente no encontrado'));
        $this->post('/admin/clientes/00000000-0000-0000-0000-000000000000/nombre', ['name' => 'Otro'])->assertNotFound();
    }

    public function test_renaming(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        $other = ClientService::create('Beta', $admin->id);
        $url = "/admin/clientes/{$c->id}";

        $this->from($url)->post("{$url}/nombre", ['name' => 'BETA'])
            ->assertSessionHasErrors(['name' => 'Ya existe un cliente con ese nombre.']);
        $this->from($url)->post("{$url}/nombre", ['name' => "Ac\nme"])
            ->assertSessionHasErrors(['name' => 'El nombre no puede tener saltos de línea.']);
        // The same name with other capitalization is allowed for the client itself.
        $this->from($url)->post("{$url}/nombre", ['name' => 'ACME'])
            ->assertStatus(303)->assertRedirect($url)
            ->assertSessionHas('success', 'Nombre guardado.');

        $this->assertSame('ACME', $c->fresh()->name);
        $this->assertSame('Beta', $other->fresh()->name);
        $this->assertSame(1, AuditLog::where('action', 'cliente.editado')->count());
    }

    public function test_colors_are_validated_saved_and_reset(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        $url = "/admin/clientes/{$c->id}";

        $this->from($url)->post("{$url}/colores", ['primary' => '#12', 'accent' => 'azul'])
            ->assertSessionHasErrors([
                'primary' => 'Color principal: use un color en formato #RRGGBB.',
                'accent' => 'Color de acento: use un color en formato #RRGGBB.',
            ]);
        $this->assertNull($c->fresh()->theme_primary);

        // Light tones are allowed (the screen only warns).
        $this->from($url)->post("{$url}/colores", ['primary' => '#FFFFFF', 'accent' => ' #C2410C '])
            ->assertStatus(303)->assertRedirect($url)
            ->assertSessionHas('success', 'Colores guardados.');
        $this->assertSame(['#ffffff', '#c2410c'], [$c->fresh()->theme_primary, $c->fresh()->theme_accent]);

        $this->from($url)->delete("{$url}/colores")
            ->assertStatus(303)->assertRedirect($url)
            ->assertSessionHas('success', 'Se restablecieron los colores de la plataforma.');
        $this->assertSame([null, null], [$c->fresh()->theme_primary, $c->fresh()->theme_accent]);
    }

    public function test_a_client_with_assessments_cannot_be_deleted(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        $this->createAssessment(['client_id' => $c->id]);

        $this->from("/admin/clientes/{$c->id}")->delete("/admin/clientes/{$c->id}")
            ->assertStatus(303)
            ->assertSessionHasErrors(['delete' => 'El cliente tiene assessments. Elimínelos o asígnelos a otro cliente primero.']);
        $this->assertNotNull($c->fresh());
        $this->assertFalse(AuditLog::where('action', 'cliente.eliminado')->exists());
    }

    public function test_deleting_a_client_without_assessments(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Vacío', $admin->id);

        $this->delete("/admin/clientes/{$c->id}")
            ->assertStatus(303)->assertRedirect('/admin/clientes?eliminado=1');
        $this->assertNull(Client::find($c->id));
        $this->assertSame(['nombre' => 'Vacío'], AuditLog::where('action', 'cliente.eliminado')->first()->metadata);
    }
}
