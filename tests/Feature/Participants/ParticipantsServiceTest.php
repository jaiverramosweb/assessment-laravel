<?php

namespace Tests\Feature\Participants;

use App\Models\AdminUser;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Submission;
use App\Services\Import\Table;
use App\Services\Participants\ParticipantExistsError;
use App\Services\Participants\ParticipantHasResponsesError;
use App\Services\Participants\ParticipantImport;
use App\Services\Participants\ParticipantInput;
use App\Services\Participants\Participants;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Port of the original participants.spec.ts (participants + import). */
class ParticipantsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin('a@b.co');
        $this->assessment = $this->createAssessment(['title' => 'Seguridad', 'timezone' => 'America/Bogota']);
    }

    /** Validated participant input (like participantSchema.parse in the original spec). */
    private function p(string $name, string $email, ?string $area = null): array
    {
        $r = ParticipantInput::validate(['name' => $name, 'email' => $email, 'area' => $area ?? '', 'cargo' => '']);
        $this->assertTrue($r['ok'], json_encode($r));

        return $r['data'];
    }

    public function test_normalizes_the_email_and_rejects_duplicates(): void
    {
        Participants::add($this->assessment->id, $this->p('Ana', ' ANA@Empresa.com '), $this->admin->id);
        $this->assertSame('ana@empresa.com', Participants::listParticipants($this->assessment->id)[0]['email']);

        try {
            Participants::add($this->assessment->id, $this->p('Ana 2', 'ana@empresa.com'), $this->admin->id);
            $this->fail('Duplicate email should throw');
        } catch (ParticipantExistsError $e) {
            $this->assertSame('Ya existe un participante con el email ana@empresa.com.', $e->getMessage());
        }

        $audit = AuditLog::where('action', 'participantes.cargados')->first();
        $this->assertSame(['nuevos' => 1, 'email' => 'ana@empresa.com'], $audit->metadata);
        $this->assertSame($this->assessment->id, $audit->entity_id);
    }

    public function test_bulk_load_creates_new_and_updates_existing(): void
    {
        Participants::add($this->assessment->id, $this->p('Ana', 'ana@empresa.com', 'TI'), $this->admin->id);
        $r = Participants::upsert(
            $this->assessment->id,
            [$this->p('Ana Pérez', 'ana@empresa.com', 'Finanzas'), $this->p('Luis', 'luis@empresa.com')],
            $this->admin->id,
        );

        $this->assertSame(['created' => 1, 'updated' => 1], $r);
        $list = Participants::listParticipants($this->assessment->id);
        $this->assertSame([['Ana Pérez', 'Finanzas'], ['Luis', null]], array_map(fn ($x) => [$x['name'], $x['area']], $list));
        $this->assertSame(['Finanzas'], Participants::distinctAreasAndCargos($this->assessment->id)['areas']);
        $this->assertContains(
            ['nuevos' => 1, 'actualizados' => 1],
            AuditLog::where('action', 'participantes.cargados')->get()->pluck('metadata')->all(),
        );
    }

    public function test_status_pending_in_progress_completed(): void
    {
        Participants::upsert(
            $this->assessment->id,
            [$this->p('Ana', 'a@x.co'), $this->p('Bea', 'b@x.co'), $this->p('Cris', 'c@x.co')],
            $this->admin->id,
        );
        [$a, $b] = Participants::listParticipants($this->assessment->id);
        Submission::create(['participant_id' => $a['id'], 'assessment_id' => $this->assessment->id]);
        Submission::create(['participant_id' => $b['id'], 'assessment_id' => $this->assessment->id, 'submitted_at' => Clock::nowMs()]);

        $list = Participants::listParticipants($this->assessment->id);
        $this->assertSame(['en_progreso', 'completado', 'pendiente'], array_column($list, 'status'));
        $counts = Participants::counts($list);
        $this->assertSame(3, $counts['total']);
        $this->assertSame(1, $counts['pendiente']);
        $this->assertSame(1, $counts['en_progreso']);
        $this->assertSame(1, $counts['completado']);
        $this->assertSame(3, $counts['sinInvitar']);
    }

    public function test_status_of(): void
    {
        $this->assertSame('pendiente', Participants::statusOf(null, null));
        $this->assertSame('en_progreso', Participants::statusOf(1, null));
        $this->assertSame('completado', Participants::statusOf(1, 2));
    }

    public function test_does_not_delete_who_already_started(): void
    {
        $ana = Participants::add($this->assessment->id, $this->p('Ana', 'ana@x.co'), $this->admin->id);
        $luis = Participants::add($this->assessment->id, $this->p('Luis', 'luis@x.co'), $this->admin->id);
        Submission::create(['participant_id' => $ana->id, 'assessment_id' => $this->assessment->id]);

        try {
            Participants::delete($this->assessment->id, $ana->id, $this->admin->id);
            $this->fail('Should not delete a participant with responses');
        } catch (ParticipantHasResponsesError $e) {
            $this->assertSame('No se puede eliminar: este participante ya empezó a responder.', $e->getMessage());
        }
        $this->assertTrue(Participants::delete($this->assessment->id, $luis->id, $this->admin->id));
        // An id from another assessment cannot be deleted from here.
        $other = $this->createAssessment();
        $this->assertFalse(Participants::delete($other->id, $ana->id, $this->admin->id));

        $this->assertSame(['email' => 'luis@x.co'], AuditLog::where('action', 'participantes.eliminado')->first()->metadata);
    }

    public function test_record_email_results_marks_invited_and_logs_each_result(): void
    {
        Clock::freeze(1_800_000_000_000);
        $ana = Participants::add($this->assessment->id, $this->p('Ana', 'ana@x.co'), $this->admin->id);
        $luis = Participants::add($this->assessment->id, $this->p('Luis', 'luis@x.co'), $this->admin->id);

        Participants::recordEmailResults([
            ['participantId' => $ana->id, 'ok' => true],
            ['participantId' => $luis->id, 'ok' => false, 'error' => str_repeat('x', 600)],
        ], $this->admin->id);

        $this->assertSame(1_800_000_000_000, $ana->fresh()->invited_at);
        $this->assertNull($luis->fresh()->invited_at);
        $logs = EmailLog::orderBy('status')->get();
        $this->assertSame(['enviado', 'error'], $logs->pluck('status')->all());
        $this->assertNull($logs[0]->error);
        $this->assertSame(500, mb_strlen($logs[1]->error));
        $this->assertSame($this->admin->id, $logs[1]->sent_by_id);

        $list = collect(Participants::listParticipants($this->assessment->id))->keyBy('email');
        $this->assertSame('enviado', $list['ana@x.co']['lastEmailStatus']);
        $this->assertSame('error', $list['luis@x.co']['lastEmailStatus']);
        $this->assertSame(1, Participants::counts($list->values()->all())['sinInvitar']);
    }

    public function test_input_validation_messages(): void
    {
        $r = ParticipantInput::validate(['name' => 'A', 'email' => 'no-es-email', 'area' => str_repeat('a', 101), 'cargo' => '']);
        $this->assertFalse($r['ok']);
        $this->assertSame([
            'name' => 'El nombre debe tener al menos 2 caracteres.',
            'email' => 'Escriba un email válido.',
            'area' => 'Área: máximo 100 caracteres.',
        ], $r['errors']);

        $r = ParticipantInput::validate(['name' => str_repeat('n', 151), 'email' => str_repeat('a', 250).'@x.co', 'cargo' => str_repeat('c', 101)]);
        $this->assertSame([
            'name' => 'El nombre no puede superar 150 caracteres.',
            'email' => 'El email es demasiado largo.',
            'cargo' => 'Cargo: máximo 100 caracteres.',
        ], $r['errors']);

        $r = ParticipantInput::validate(['name' => '  Ana  ', 'email' => 'ANA@X.CO', 'area' => '  ', 'cargo' => ' Jefe ']);
        $this->assertSame(['name' => 'Ana', 'email' => 'ana@x.co', 'area' => null, 'cargo' => 'Jefe', 'extra' => null], $r['data']);
    }

    public function test_import_reads_known_columns_and_keeps_others_as_extra(): void
    {
        $r = ParticipantImport::parse(new Table(
            ['nombre', 'correo', 'area', 'cargo', 'sede'],
            [['Ana', 'ANA@x.co', 'TI', 'Analista', 'Bogotá']],
        ));
        $this->assertSame([
            'ok' => true,
            'participants' => [[
                'name' => 'Ana', 'email' => 'ana@x.co', 'area' => 'TI', 'cargo' => 'Analista', 'extra' => ['sede' => 'Bogotá'],
            ]],
        ], $r);
    }

    public function test_import_reports_invalid_and_repeated_emails_with_row_number(): void
    {
        $r = ParticipantImport::parse(new Table(
            ['nombre', 'email'],
            [['Ana', 'ana@x.co'], ['Luis', 'no-es-email'], ['Ana bis', 'Ana@x.co']],
        ));
        $this->assertSame([
            'ok' => false,
            'errors' => [
                'Fila 3: Escriba un email válido.',
                'Fila 4: el email ana@x.co ya aparece en la fila 2.',
            ],
        ], $r);
    }

    public function test_import_requires_name_and_email_columns(): void
    {
        $r = ParticipantImport::parse(new Table(['nombre', 'area'], [['Ana', 'TI']]));
        $this->assertSame(
            ['ok' => false, 'errors' => ['El archivo debe tener las columnas "nombre" y "email". Descargue la plantilla.']],
            $r,
        );
    }
}
