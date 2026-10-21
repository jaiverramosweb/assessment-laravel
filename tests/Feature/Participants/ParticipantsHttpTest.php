<?php

namespace Tests\Feature\Participants;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Participant;
use App\Models\Submission;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ParticipantsHttpTest extends TestCase
{
    use RefreshDatabase;

    private Assessment $assessment;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assessment = $this->createAssessment(['title' => 'Clima de seguridad']);
        $this->base = "/admin/assessments/{$this->assessment->id}/participantes";
    }

    public function test_requires_an_admin_session(): void
    {
        $this->get($this->base)->assertRedirect();
        $this->post($this->base, ['name' => 'Ana', 'email' => 'ana@x.co'])->assertRedirect();
        $this->assertSame(0, Participant::count());
    }

    public function test_unknown_assessment_is_404(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/assessments/00000000-0000-0000-0000-000000000000/participantes')->assertNotFound();
    }

    public function test_index_lists_participants_with_counts_filters_and_context(): void
    {
        $this->actingAsAdmin();
        $ana = $this->createParticipant($this->assessment, ['name' => 'Ana Pérez', 'email' => 'ana@empresa.test', 'area' => 'Tecnología', 'cargo' => 'Analista']);
        $this->createParticipant($this->assessment, ['name' => 'Luis Gómez', 'email' => 'luis@empresa.test', 'area' => 'Finanzas']);
        $this->createParticipant($this->assessment, ['name' => 'Eva Ruiz', 'email' => 'eva@empresa.test', 'area' => 'Tecnología', 'invited_at' => 1000]);
        Submission::create(['participant_id' => $ana->id, 'assessment_id' => $this->assessment->id]);

        $this->get($this->base)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assessments/Participants')
                ->where('context.assessment.id', $this->assessment->id)
                ->where('context.participantCount', 3)
                ->has('participants', 3)
                ->where('participants.0.name', 'Ana Pérez')
                ->where('participants.0.status', 'en_progreso')
                ->where('counts', ['total' => 3, 'pendiente' => 2, 'en_progreso' => 1, 'completado' => 0, 'sinInvitar' => 2])
                ->where('filters', ['estado' => '', 'area' => '', 'cargo' => '', 'q' => ''])
                ->where('statuses', ['pendiente', 'en_progreso', 'completado'])
                ->where('areas', ['Finanzas', 'Tecnología'])
                ->where('cargos', ['Analista'])
                ->where('outcome', null));

        $this->get($this->base.'?area=Tecnolog%C3%ADa')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants', 2)
                ->where('counts.total', 3)
                ->where('filters.area', 'Tecnología'));

        $this->get($this->base.'?estado=pendiente&q=EVA')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants', 1)
                ->where('participants.0.email', 'eva@empresa.test')
                ->where('filters.q', 'eva'));
    }

    public function test_add_participant_normalizes_email_and_audits(): void
    {
        $admin = $this->actingAsAdmin();

        $this->from($this->base)
            ->post($this->base, ['name' => 'Ana Pérez', 'email' => ' ANA@empresa.test ', 'area' => 'TI', 'cargo' => ''])
            ->assertStatus(303)
            ->assertRedirect($this->base)
            ->assertSessionHas('outcome', ['added' => 'ana@empresa.test']);

        $p = Participant::sole();
        $this->assertSame('ana@empresa.test', $p->email);
        $this->assertSame('TI', $p->area);
        $this->assertNull($p->cargo);
        $this->assertSame('cargado', $p->source);
        $this->assertTrue(AuditLog::where('action', 'participantes.cargados')->where('admin_id', $admin->id)->exists());

        $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page->where('outcome.added', 'ana@empresa.test'));
    }

    public function test_add_participant_validation_and_duplicate_errors(): void
    {
        $this->actingAsAdmin();
        $this->createParticipant($this->assessment, ['name' => 'Ana', 'email' => 'ana@empresa.test']);

        $this->from($this->base)
            ->post($this->base, ['name' => 'A', 'email' => 'no-es-email'])
            ->assertStatus(303)
            ->assertSessionHasErrors([
                'name' => 'El nombre debe tener al menos 2 caracteres.',
                'email' => 'Escriba un email válido.',
            ]);

        $this->from($this->base)
            ->post($this->base, ['name' => 'Otra Ana', 'email' => 'ANA@empresa.test'])
            ->assertStatus(303)
            ->assertSessionHasErrors(['email' => 'Ya existe un participante con el email ana@empresa.test.']);

        $this->assertSame(1, Participant::count());
    }

    public function test_import_csv_creates_and_updates(): void
    {
        $this->actingAsAdmin();
        $this->createParticipant($this->assessment, ['name' => 'Ana', 'email' => 'ana@empresa.test']);
        $csv = "\u{FEFF}Nombre;Correo;Área;Cargo;Sede\n"
            ."Ana Pérez;ana@empresa.test;Tecnología;Analista;Bogotá\n"
            ."Luis Gómez;luis@empresa.test;Finanzas;Coordinador;Medellín\n"
            ."Eva Ruiz;eva@empresa.test;Tecnología;Directora;Cali\n";

        $this->from($this->base)
            ->post($this->base.'/importar', ['archivo' => UploadedFile::fake()->createWithContent('participantes.csv', $csv)])
            ->assertStatus(303)
            ->assertSessionHas('outcome', ['imported' => ['created' => 2, 'updated' => 1]]);

        $this->assertSame(3, Participant::count());
        $ana = Participant::where('email', 'ana@empresa.test')->sole();
        $this->assertSame('Ana Pérez', $ana->name);
        $this->assertSame(['sede' => 'Bogotá'], $ana->extra);
        $this->assertSame(
            ['nuevos' => 2, 'actualizados' => 1],
            AuditLog::where('action', 'participantes.cargados')->sole()->metadata,
        );
    }

    public function test_import_errors_load_nothing(): void
    {
        $this->actingAsAdmin();
        $csv = "nombre,email\nAna,ana@x.co\nLuis,no-es-email\n";

        $this->from($this->base)
            ->post($this->base.'/importar', ['archivo' => UploadedFile::fake()->createWithContent('p.csv', $csv)])
            ->assertStatus(303)
            ->assertSessionHas('outcome', ['importErrors' => ['Fila 3: Escriba un email válido.']]);
        $this->assertSame(0, Participant::count());

        $this->from($this->base)
            ->post($this->base.'/importar', ['archivo' => UploadedFile::fake()->createWithContent('p.txt', 'x')])
            ->assertSessionHas('outcome', ['importErrors' => ['Formato no soportado. Use un archivo .xlsx o .csv.']]);

        $this->from($this->base)
            ->post($this->base.'/importar', [])
            ->assertSessionHas('outcome', ['importErrors' => ['Seleccione un archivo.']]);
    }

    public function test_import_xlsx_from_the_template(): void
    {
        $this->actingAsAdmin();
        $response = $this->get($this->base.'/plantilla')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->getContent());

        $this->from($this->base)
            ->post($this->base.'/importar', ['archivo' => new UploadedFile($path, 'plantilla.xlsx', null, null, true)])
            ->assertSessionHas('outcome', ['imported' => ['created' => 2, 'updated' => 0]]);
        $this->assertSame(['ana.perez@empresa.com', 'luis.gomez@empresa.com'], Participant::orderBy('email')->pluck('email')->all());
        @unlink($path);
    }

    public function test_template_download_headers_and_sheets(): void
    {
        $this->actingAsAdmin();
        $response = $this->get($this->base.'/plantilla')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="plantilla-participantes.xlsx"');

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $book = IOFactory::createReader('Xlsx')->load($path);
        @unlink($path);
        $this->assertSame(['Participantes', 'Instrucciones'], $book->getSheetNames());
        $this->assertSame(['nombre', 'email', 'area', 'cargo'], $book->getSheet(0)->rangeToArray('A1:D1')[0]);
        $this->assertSame('Ana Pérez', $book->getSheet(0)->getCell('A2')->getValue());
    }

    public function test_delete_pending_participant_but_not_one_who_started(): void
    {
        $this->actingAsAdmin();
        $eva = $this->createParticipant($this->assessment, ['name' => 'Eva', 'email' => 'eva@x.co']);
        $ana = $this->createParticipant($this->assessment, ['name' => 'Ana', 'email' => 'ana@x.co']);
        Submission::create(['participant_id' => $ana->id, 'assessment_id' => $this->assessment->id]);

        $this->from($this->base)->delete("{$this->base}/{$eva->id}")
            ->assertStatus(303)->assertRedirect($this->base)->assertSessionHas('outcome', ['deleted' => true]);
        $this->assertNull($eva->fresh());
        $this->assertSame(['email' => 'eva@x.co'], AuditLog::where('action', 'participantes.eliminado')->sole()->metadata);

        $this->from($this->base)->delete("{$this->base}/{$ana->id}")
            ->assertSessionHas('outcome', ['deleteError' => 'No se puede eliminar: este participante ya empezó a responder.']);
        $this->assertNotNull($ana->fresh());

        // A participant from another assessment cannot be deleted from here.
        $other = $this->createParticipant($this->createAssessment(), ['email' => 'otro@x.co']);
        $this->from($this->base)->delete("{$this->base}/{$other->id}")
            ->assertSessionHas('outcome', ['deleteError' => 'El participante no existe.']);
        $this->assertNotNull($other->fresh());
    }

    public function test_last_email_error_is_listed(): void
    {
        $this->actingAsAdmin();
        $p = $this->createParticipant($this->assessment, ['email' => 'x@x.co']);
        Clock::freeze(1000);
        EmailLog::create(['participant_id' => $p->id, 'status' => 'enviado']);
        Clock::freeze(2000);
        EmailLog::create(['participant_id' => $p->id, 'status' => 'error', 'error' => 'Buzón inexistente']);

        $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('participants.0.lastEmailStatus', 'error')
            ->where('participants.0.lastEmailError', 'Buzón inexistente'));
    }
}
