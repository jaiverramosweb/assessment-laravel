<?php

namespace Tests\Feature\Invitations;

use App\Auth\Passwords;
use App\Mail\InvitationMail;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Participant;
use App\Security\SecretBox;
use App\Services\Invitations\InviteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InvitationsHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'acceso-e2e-2026';

    private Assessment $assessment;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://assesment.sisteltestserver.com']);
        $this->assessment = $this->createAssessment(['title' => 'Clima de seguridad', 'status' => 'publicado']);
        $this->base = "/admin/assessments/{$this->assessment->id}/invitaciones";
    }

    private function setPassword(bool $stored = true): void
    {
        $this->assessment->update([
            'access_password_hash' => Passwords::hash(self::PASSWORD),
            'access_password_enc' => $stored ? SecretBox::encrypt(self::PASSWORD) : null,
        ]);
    }

    public function test_requires_an_admin_session(): void
    {
        $this->get($this->base)->assertRedirect();
        $this->post($this->base.'/enviar')->assertRedirect();
    }

    public function test_index_props(): void
    {
        $this->actingAsAdmin();
        $this->setPassword();
        $this->createParticipant($this->assessment, ['name' => 'Ana Pérez', 'email' => 'ana@x.co']);
        $this->createParticipant($this->assessment, ['name' => 'Luis', 'email' => 'luis@x.co', 'invited_at' => 1]);

        $this->get($this->base)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Assessments/Invitations')
                ->where('context.assessment.id', $this->assessment->id)
                ->where('template', ['subject' => InviteTemplate::DEFAULT_SUBJECT, 'body' => InviteTemplate::DEFAULT_BODY])
                ->where('placeholders', InviteTemplate::PLACEHOLDERS)
                ->where('previewVars.nombre', 'Ana Pérez')
                ->where('previewVars.contrasena', '(contraseña de acceso)')
                ->where('previewVars.enlace', "https://assesment.sisteltestserver.com/a/{$this->assessment->slug}")
                ->where('recipientCounts', ['sin_invitar' => 1, 'no_completados' => 2])
                ->where('mailerKind', 'consola')
                ->where('passwordStored', true)
                ->where('outcome', null));
    }

    public function test_index_never_exposes_the_password(): void
    {
        $this->actingAsAdmin();
        $this->setPassword();
        $this->get($this->base)->assertOk()->assertDontSee(self::PASSWORD);
    }

    public function test_save_template_validates_and_audits(): void
    {
        $this->actingAsAdmin();

        $this->from($this->base)
            ->post($this->base.'/plantilla', ['subject' => 'Hola', 'body' => 'Hola {{nombre}}, su clave es {{contrasena}}'])
            ->assertStatus(303)
            ->assertSessionHasErrors(['body' => 'El mensaje debe incluir {{enlace}}.']);
        $this->assertSame('', (string) $this->assessment->fresh()->invite_body);

        $this->from($this->base)
            ->post($this->base.'/plantilla', ['subject' => ' Nuevo asunto ', 'body' => '{{enlace}} {{contrasena}}'])
            ->assertStatus(303)
            ->assertRedirect($this->base)
            ->assertSessionHas('outcome', ['templateSaved' => true]);

        $a = $this->assessment->fresh();
        $this->assertSame('Nuevo asunto', $a->invite_subject);
        $this->assertSame('{{enlace}} {{contrasena}}', $a->invite_body);
        $this->assertSame(['cambio' => 'plantilla de invitación'], AuditLog::where('action', 'assessment.editado')->sole()->metadata);
    }

    public function test_send_uses_the_stored_password_and_records_results(): void
    {
        Mail::fake();
        $admin = $this->actingAsAdmin();
        $this->setPassword();
        $ana = $this->createParticipant($this->assessment, ['name' => 'Ana', 'email' => 'ana@x.co']);
        $this->createParticipant($this->assessment, ['name' => 'Luis', 'email' => 'luis@x.co']);

        $this->from($this->base)
            ->post($this->base.'/enviar', ['destinatarios' => 'sin_invitar'])
            ->assertStatus(303)
            ->assertSessionHas('outcome', ['sendResult' => ['sent' => 2, 'failed' => 0, 'total' => 2]]);

        Mail::assertSentCount(2);
        Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('ana@x.co')
            && $m->subjectLine === 'Invitación: Clima de seguridad'
            && str_contains($m->bodyText, 'Hola Ana,')
            && str_contains($m->bodyText, 'Contraseña de acceso: '.self::PASSWORD)
            && str_contains($m->bodyText, "https://assesment.sisteltestserver.com/a/{$this->assessment->slug}"));

        $this->assertSame(2, EmailLog::where('status', 'enviado')->where('sent_by_id', $admin->id)->count());
        $this->assertNotNull($ana->fresh()->invited_at);
        $audit = AuditLog::where('action', 'invitaciones.enviadas')->sole();
        $this->assertSame(['enviados' => 2, 'fallidos' => 0, 'destinatarios' => 'sin_invitar', 'via' => 'consola'], $audit->metadata);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit->metadata));

        // Nobody left to invite.
        $this->from($this->base)
            ->post($this->base.'/enviar', ['destinatarios' => 'sin_invitar'])
            ->assertSessionHas('outcome', ['sendError' => 'No hay participantes que cumplan el criterio seleccionado.']);
        Mail::assertSentCount(2);

        // Reminder to everyone who has not submitted.
        $this->from($this->base)
            ->post($this->base.'/enviar', ['destinatarios' => 'no_completados'])
            ->assertSessionHas('outcome', ['sendResult' => ['sent' => 2, 'failed' => 0, 'total' => 2]]);
    }

    public function test_send_failures_are_recorded_per_recipient(): void
    {
        $this->actingAsAdmin();
        $this->setPassword();
        $this->createParticipant($this->assessment, ['name' => 'Ana', 'email' => 'ana@x.co']);
        // A transport that always fails: the batch continues and each error is logged.
        config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);

        $this->from($this->base)
            ->post($this->base.'/enviar', ['destinatarios' => 'sin_invitar'])
            ->assertSessionHas('outcome', ['sendResult' => ['sent' => 0, 'failed' => 1, 'total' => 1]]);
        $log = EmailLog::sole();
        $this->assertSame('error', $log->status);
        $this->assertNotEmpty($log->error);
        $this->assertSame('smtp', AuditLog::where('action', 'invitaciones.enviadas')->sole()->metadata['via']);
    }

    public function test_send_requires_published_and_correct_password(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $this->setPassword(stored: false);
        $this->createParticipant($this->assessment, ['email' => 'ana@x.co']);

        $this->from($this->base)
            ->post($this->base.'/enviar', ['destinatarios' => 'sin_invitar', 'password' => 'otra'])
            ->assertSessionHas('outcome', ['sendError' => 'La contraseña no coincide con la contraseña de acceso vigente.']);

        $this->from($this->base)
            ->post($this->base.'/enviar', ['destinatarios' => 'sin_invitar', 'password' => self::PASSWORD])
            ->assertSessionHas('outcome', ['sendResult' => ['sent' => 1, 'failed' => 0, 'total' => 1]]);

        $this->assessment->update(['status' => 'borrador']);
        $this->from($this->base)
            ->post($this->base.'/enviar', ['destinatarios' => 'no_completados', 'password' => self::PASSWORD])
            ->assertSessionHas('outcome', ['sendError' => 'Publique el assessment antes de enviar invitaciones.']);
        Mail::assertSentCount(1);
    }

    public function test_copy_generates_the_generic_message(): void
    {
        $this->actingAsAdmin();
        $this->setPassword();

        $this->from($this->base)
            ->post($this->base.'/copiar')
            ->assertStatus(303)
            ->assertSessionHas('outcome', fn ($o) => str_starts_with($o['generic']['body'], 'Hola,')
                && $o['generic']['subject'] === 'Invitación: Clima de seguridad'
                && str_contains($o['generic']['body'], 'Contraseña de acceso: '.self::PASSWORD));

        $this->assessment->update(['access_password_enc' => null]);
        $this->from($this->base)
            ->post($this->base.'/copiar', ['password' => 'otra'])
            ->assertSessionHas('outcome', ['copyError' => 'La contraseña no coincide con la contraseña de acceso vigente.']);
        $this->from($this->base)
            ->post($this->base.'/copiar', ['password' => self::PASSWORD])
            ->assertSessionHas('outcome', fn ($o) => isset($o['generic']));
    }
}
