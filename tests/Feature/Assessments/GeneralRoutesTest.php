<?php

namespace Tests\Feature\Assessments;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Participant;
use App\Models\Submission;
use App\Services\Assessments\Assessments;
use App\Services\Assessments\Questions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\Assessments\Builders;
use Tests\TestCase;

class GeneralRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Assessment $a;

    private string $base;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->actingAsAdmin();
        $this->adminId = $admin->id;
        $this->a = Assessments::create(Builders::input(), $admin->id);
        $this->base = "/admin/assessments/{$this->a->id}";
    }

    public function test_unknown_or_malformed_ids_are_404(): void
    {
        $this->get('/admin/assessments/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->get('/admin/assessments/no-es-uuid')->assertNotFound();
        $this->post('/admin/assessments/00000000-0000-0000-0000-000000000000/publicar')->assertNotFound();
    }

    public function test_show_renders_the_general_tab(): void
    {
        $this->get($this->base)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Assessments/General')
            ->where('context.assessment.id', $this->a->id)
            ->where('values.title', 'Evaluación de seguridad')
            ->where('values.startsAt', '2026-11-01T08:00')
            ->where('values.endsAt', '2026-11-15T18:00')
            ->where('values.openMaxChars', 8000)
            ->where('values.clientId', '')
            ->where('publishProblems', ['Defina la contraseña de acceso.', 'Agregue al menos una pregunta.'])
            ->where('deletion', ['questions' => 0, 'participants' => 0, 'submitted' => 0])
            ->where('result', null)
            ->has('timezones'));
    }

    public function test_save_validates_and_updates(): void
    {
        $this->from($this->base)->post("{$this->base}/guardar", Builders::formValues([
            'startsAt' => '2026-12-10T08:00', 'endsAt' => '2026-12-01T08:00',
        ]))->assertRedirect($this->base)
            ->assertSessionHasErrors(['endsAt' => 'La finalización debe ser posterior al inicio.']);

        $client = Client::create(['name' => 'Acme']);
        $this->post("{$this->base}/guardar", Builders::formValues([
            'title' => 'Nuevo título', 'clientId' => $client->id, 'startsAt' => '2026-12-10T08:00', 'endsAt' => '2026-12-20T18:00',
        ]))->assertStatus(303)->assertRedirect($this->base)->assertSessionHas('result', ['saved' => true]);

        $a = $this->a->fresh();
        $this->assertSame(['Nuevo título', $client->id], [$a->title, $a->client_id]);
        $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('values.startsAt', '2026-12-10T08:00'));
        $this->assertSame(['titulo' => 'Nuevo título'], AuditLog::where('action', 'assessment.editado')->first()->metadata);
    }

    public function test_access_password_set_reveal_and_audit(): void
    {
        $this->from($this->base)->post("{$this->base}/password", ['password' => 'acceso-2026-ok', 'confirm' => 'otra-cosa'])
            ->assertSessionHasErrors(['confirm' => 'Las contraseñas no coinciden.']);
        $this->from($this->base)->post("{$this->base}/password", ['password' => 'corta', 'confirm' => 'corta'])
            ->assertSessionHasErrors(['password' => 'La contraseña de acceso debe tener al menos 12 caracteres.']);
        $this->assertNull($this->a->fresh()->access_password_hash);

        $this->post("{$this->base}/password", ['password' => 'acceso-2026-ok', 'confirm' => 'acceso-2026-ok'])
            ->assertRedirect($this->base)->assertSessionHas('result', ['passwordSaved' => true]);
        $this->assertNotNull($this->a->fresh()->access_password_hash);

        $this->withCredentials()->postJson("{$this->base}/ver-password")->assertOk()->assertExactJson(['password' => 'acceso-2026-ok']);
        $this->assertSame(1, AuditLog::where('action', 'assessment.password_vista')->count());

        // Only a hash (defined before it could be revealed): it cannot be shown.
        $this->a->fresh()->forceFill(['access_password_enc' => null])->save();
        $this->withCredentials()->postJson("{$this->base}/ver-password")->assertStatus(400)->assertExactJson([
            'error' => 'Esta contraseña se definió antes de poder consultarse. Defínala de nuevo para poder verla.',
        ]);
    }

    public function test_publish_requires_problems_solved_then_changes_status(): void
    {
        $this->post("{$this->base}/publicar")->assertRedirect($this->base)
            ->assertSessionHas('result', ['publishErrors' => ['Defina la contraseña de acceso.', 'Agregue al menos una pregunta.']]);
        $this->assertSame('borrador', $this->a->fresh()->status);

        Assessments::setAccessPassword($this->a->id, 'acceso-2026-ok', $this->adminId);
        Questions::replace($this->a->id, Builders::questions(), $this->adminId);
        $this->post("{$this->base}/publicar")->assertRedirect($this->base)
            ->assertSessionHas('result', ['statusChanged' => 'publicado']);
        $this->assertSame('publicado', $this->a->fresh()->status);

        $this->post("{$this->base}/archivar")->assertSessionHas('result', ['statusChanged' => 'archivado']);
        $this->assertSame('archivado', $this->a->fresh()->status);
        $this->post("{$this->base}/borrador")->assertSessionHas('result', ['statusChanged' => 'borrador']);
        $this->assertSame('borrador', $this->a->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'assessment.archivado')->count());
    }

    public function test_duplicate_redirects_to_the_copy(): void
    {
        $response = $this->post("{$this->base}/duplicar");
        $copy = Assessment::where('duplicated_from_id', $this->a->id)->firstOrFail();
        $response->assertStatus(303)->assertRedirect("/admin/assessments/{$copy->id}?duplicado=1");
        $this->assertSame('Copia de Evaluación de seguridad', $copy->title);
    }

    public function test_next_measurement_requires_questions_then_redirects(): void
    {
        $this->from($this->base)->post("{$this->base}/medicion")->assertRedirect($this->base)
            ->assertSessionHasErrors(['medicion' => 'Agregue preguntas antes de crear otra medición.']);

        Questions::replace($this->a->id, Builders::questions(), $this->adminId);
        $response = $this->post("{$this->base}/medicion");
        $next = Assessment::where('series_order', 2)->firstOrFail();
        $response->assertRedirect("/admin/assessments/{$next->id}?medicion=1");
        $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('context.series', 2)->where('context.locked', true));
    }

    public function test_delete_requires_the_exact_title(): void
    {
        $p = $this->createParticipant($this->a);
        Submission::create(['assessment_id' => $this->a->id, 'participant_id' => $p->id, 'submitted_at' => 5]);
        $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('deletion', ['questions' => 0, 'participants' => 1, 'submitted' => 1]));

        $this->from($this->base)->post("{$this->base}/eliminar", ['confirmacion' => 'otro título'])
            ->assertRedirect($this->base)
            ->assertSessionHasErrors(['confirmacion' => 'Escriba el título exacto del assessment para confirmar la eliminación.']);
        $this->assertNotNull($this->a->fresh());

        $this->post("{$this->base}/eliminar", ['confirmacion' => ' Evaluación de seguridad '])
            ->assertStatus(303)->assertRedirect('/admin?eliminado=1');
        $this->assertNull(Assessment::find($this->a->id));
        $this->assertSame(0, Participant::count());
        $this->assertSame(1, AuditLog::where('action', 'assessment.eliminado')->count());
        $this->get($this->base)->assertNotFound();
    }
}
