<?php

namespace Tests\Feature\Assessments;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Assessments\Assessments;
use App\Services\Assessments\Questions;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\Assessments\Builders;
use Tests\TestCase;

class DashboardAndCreateRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_require_a_session(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login?destino=%2Fadmin');
        $this->get('/admin/assessments/nuevo')->assertStatus(303);
    }

    public function test_dashboard_lists_with_kpis_search_client_filter_and_archived(): void
    {
        $admin = $this->actingAsAdmin();
        $client = Client::create(['name' => 'Acme', 'logo_mime' => 'image/png', 'logo_updated_at' => 55]);
        Clock::freeze(1_000_000);
        $draft = Assessments::create(Builders::input(['title' => 'Borrador uno']), $admin->id);
        Clock::freeze(2_000_000);
        $pub = Assessments::create(Builders::input(['title' => 'Publicado ACME', 'clientId' => $client->id]), $admin->id);
        Questions::replace($pub->id, Builders::questions(), $admin->id);
        Assessments::setStatus($pub, 'publicado', $admin->id);
        $this->createParticipant($pub);
        Clock::freeze(3_000_000);
        $arch = Assessments::create(Builders::input(['title' => 'Archivado']), $admin->id);
        Assessments::setStatus($arch, 'archivado', $admin->id);

        $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Assessments/Index')
            ->where('includeArchived', false)
            ->where('q', '')
            ->where('clientId', '')
            ->where('clients', [['id' => $client->id, 'name' => 'Acme']])
            ->has('assessments', 2)
            ->where('assessments.0.id', $pub->id)
            ->where('assessments.0.clientName', 'Acme')
            ->where('assessments.0.logoUrl', "/a/{$pub->slug}/logo?v=55")
            ->where('assessments.0.questionCount', 2)
            ->where('assessments.0.participantCount', 1)
            ->where('assessments.0.notInvitedCount', 1)
            ->where('assessments.0.averageAlignment', null)
            ->where('assessments.1.id', $draft->id)
            ->where('kpis.published', 1)
            ->where('kpis.drafts', 1)
            ->where('kpis.participants', 1)
            ->where('kpis.participationRate', 0)
            ->where('kpis.averageAlignment', null)
            ->where('kpis.alignmentCount', 0)
            ->missing('assessments.0.access_password_hash'));

        $this->get('/admin?q=acme')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('q', 'acme')->has('assessments', 1)->where('assessments.0.id', $pub->id));
        $this->get('/admin?cliente='.$client->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clientId', $client->id)->has('assessments', 1));
        $this->get('/admin?cliente=no-existe')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clientId', '')->has('assessments', 2));
        $this->get('/admin?archivados=1')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('includeArchived', true)->has('assessments', 3)->where('assessments.0.id', $arch->id));
    }

    public function test_new_assessment_form_defaults_and_client_preset(): void
    {
        Clock::freeze(1793538000000); // 2026-11-01T13:00Z = 08:00 in Bogotá
        $this->actingAsAdmin();
        $client = Client::create(['name' => 'Acme']);

        $this->get('/admin/assessments/nuevo?cliente='.$client->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assessments/Create')
                ->where('values.clientId', $client->id)
                ->where('values.timezone', 'America/Bogota')
                ->where('values.startsAt', '2026-11-02T08:00')
                ->where('values.endsAt', '2026-11-09T18:00')
                ->where('values.accessMode', 'invitados')
                ->where('values.openMaxChars', 8000)
                ->where('timezones.0', 'America/Bogota')
                ->where('clients', [['id' => $client->id, 'name' => 'Acme']]));

        $this->get('/admin/assessments/nuevo?cliente=otro')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('values.clientId', ''));
    }

    public function test_create_validates_and_redirects_to_questions(): void
    {
        $admin = $this->actingAsAdmin();

        $this->from('/admin/assessments/nuevo')
            ->post('/admin/assessments/nuevo', Builders::formValues(['title' => 'ab']))
            ->assertRedirect('/admin/assessments/nuevo')
            ->assertSessionHasErrors(['title' => 'El título debe tener al menos 3 caracteres.']);
        $this->from('/admin/assessments/nuevo')
            ->post('/admin/assessments/nuevo', Builders::formValues(['clientId' => '00000000-0000-0000-0000-000000000000']))
            ->assertSessionHasErrors(['clientId' => 'El cliente elegido no existe.']);
        $this->assertSame(0, Assessment::count());

        $response = $this->post('/admin/assessments/nuevo', Builders::formValues(['title' => '  Seguridad  ']));
        $a = Assessment::firstOrFail();
        $response->assertStatus(303)->assertRedirect("/admin/assessments/{$a->id}/preguntas?creado=1");
        $this->assertSame('Seguridad', $a->title);
        $this->assertSame($admin->id, $a->created_by_id);
        $this->assertSame('borrador', $a->status);
        $this->assertMatchesRegularExpression('/^[a-km-np-z2-9]{12}$/', $a->slug);
        $log = AuditLog::where('action', 'assessment.creado')->firstOrFail();
        $this->assertSame([$admin->id, $a->id, '127.0.0.1'], [$log->admin_id, $log->entity_id, $log->ip]);
    }
}
