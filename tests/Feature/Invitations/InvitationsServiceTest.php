<?php

namespace Tests\Feature\Invitations;

use App\Auth\Passwords;
use App\Models\AdminUser;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Submission;
use App\Security\SecretBox;
use App\Services\Invitations\Invitations;
use App\Services\Participants\ParticipantInput;
use App\Services\Participants\Participants;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Port of the "envío de invitaciones" cases of the original participants.spec.ts. */
class InvitationsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin('a@b.co');
        // 2026-11-01 08:00 and 2026-11-15 18:00 in Bogotá (UTC-5).
        $this->assessment = $this->createAssessment([
            'title' => 'Seguridad',
            'timezone' => 'America/Bogota',
            'starts_at' => 1_793_538_000_000,
            'ends_at' => 1_794_783_600_000,
        ]);
    }

    private function setPassword(string $password): void
    {
        $this->assessment->update([
            'access_password_hash' => Passwords::hash($password),
            'access_password_enc' => SecretBox::encrypt($password),
        ]);
        $this->assessment->refresh();
    }

    private function p(string $name, string $email): array
    {
        return ParticipantInput::validate(['name' => $name, 'email' => $email])['data'];
    }

    /** Fake delivery: records messages and fails for the given addresses. */
    private function fakeDeliver(array &$sent, array $failFor = []): \Closure
    {
        return function (string $to, string $subject, string $text, string $html) use (&$sent, $failFor) {
            if (in_array($to, $failFor, true)) {
                throw new RuntimeException('Buzón inexistente');
            }
            $sent[] = compact('to', 'subject', 'text', 'html');
        };
    }

    public function test_verifies_the_access_password_typed_by_the_admin(): void
    {
        $this->setPassword('acceso-2026');
        $this->assertTrue(Invitations::checkAccessPassword($this->assessment, 'acceso-2026'));
        $this->assertFalse(Invitations::checkAccessPassword($this->assessment, 'otra'));
        $this->assertFalse(Invitations::checkAccessPassword($this->assessment, ''));
        $this->assertSame('acceso-2026', Invitations::storedPassword($this->assessment));
    }

    public function test_sends_to_not_invited_personalizes_and_records_results(): void
    {
        $this->setPassword('acceso-2026');
        Participants::upsert($this->assessment->id, [$this->p('Ana', 'ana@x.co'), $this->p('Luis', 'luis@x.co'), $this->p('Eva', 'eva@x.co')], $this->admin->id);
        $eva = collect(Participants::listParticipants($this->assessment->id))->firstWhere('email', 'eva@x.co');
        Submission::create(['participant_id' => $eva['id'], 'assessment_id' => $this->assessment->id, 'submitted_at' => Clock::nowMs()]);

        $sent = [];
        $r = Invitations::send($this->assessment, 'sin_invitar', 'acceso-2026', $this->admin->id, null,
            'https://evaluaciones.ejemplo.com/', $this->fakeDeliver($sent, ['luis@x.co']));

        $this->assertSame(['sent' => 1, 'failed' => 1, 'total' => 2], $r);
        $this->assertCount(1, $sent);
        $this->assertSame('ana@x.co', $sent[0]['to']);
        $this->assertSame('Invitación: Seguridad', $sent[0]['subject']);
        $this->assertStringContainsString('Hola Ana,', $sent[0]['text']);
        $this->assertStringContainsString("https://evaluaciones.ejemplo.com/a/{$this->assessment->slug}", $sent[0]['text']);
        $this->assertStringContainsString('Contraseña de acceso: acceso-2026', $sent[0]['text']);
        $this->assertStringContainsString('1 de noviembre de 2026', $sent[0]['text']);
        $this->assertStringContainsString('hora de America/Bogota', $sent[0]['text']);
        $this->assertStringContainsString('<a href="https://evaluaciones.ejemplo.com/a/', $sent[0]['html']);

        $list = collect(Participants::listParticipants($this->assessment->id))->keyBy('email');
        $this->assertIsInt($list['ana@x.co']['invitedAt']);
        $this->assertSame('enviado', $list['ana@x.co']['lastEmailStatus']);
        $this->assertNull($list['luis@x.co']['invitedAt']);
        $this->assertSame('error', $list['luis@x.co']['lastEmailStatus']);
        $this->assertSame('Buzón inexistente', $list['luis@x.co']['lastEmailError']);
        $this->assertSame(2, EmailLog::count());

        // A second "sin invitar" send only retries Luis.
        $sent2 = [];
        $again = Invitations::send($this->assessment, 'sin_invitar', 'acceso-2026', $this->admin->id, null,
            'https://x.co', $this->fakeDeliver($sent2));
        $this->assertSame(['sent' => 1, 'failed' => 0, 'total' => 1], $again);
        $this->assertSame('luis@x.co', $sent2[0]['to']);

        $audits = AuditLog::where('action', 'invitaciones.enviadas')->orderBy('created_at')->get();
        $this->assertSame(['enviados' => 1, 'fallidos' => 1, 'destinatarios' => 'sin_invitar', 'via' => 'consola'], $audits[0]->metadata);
        // The password never ends up in the audit log.
        $this->assertStringNotContainsString('acceso-2026', $audits->toJson());
    }

    public function test_reminder_mode_targets_everyone_who_has_not_submitted(): void
    {
        Participants::upsert($this->assessment->id, [$this->p('Ana', 'ana@x.co'), $this->p('Eva', 'eva@x.co')], $this->admin->id);
        $list = Participants::listParticipants($this->assessment->id);
        Participants::recordEmailResults([['participantId' => $list[0]['id'], 'ok' => true]], $this->admin->id);
        $list = Participants::listParticipants($this->assessment->id);

        $this->assertCount(1, Invitations::selectRecipients($list, 'sin_invitar'));
        $this->assertCount(2, Invitations::selectRecipients($list, 'no_completados'));
    }

    public function test_generic_message_to_copy(): void
    {
        $m = Invitations::genericMessage($this->assessment, 'acceso-2026', 'http://localhost:5173');
        $this->assertStringStartsWith('Hola,', $m['body']);
        $this->assertStringContainsString("http://localhost:5173/a/{$this->assessment->slug}", $m['body']);
        $this->assertSame('Invitación: Seguridad', $m['subject']);
    }

    public function test_public_link_and_saved_template(): void
    {
        $this->assertSame('https://x.co/a/abc', Invitations::publicLink('https://x.co/', 'abc'));
        $this->assertSame(
            ['subject' => 'Invitación: {{titulo}}', 'body' => \App\Services\Invitations\InviteTemplate::DEFAULT_BODY],
            Invitations::template($this->assessment),
        );

        Invitations::saveTemplate($this->assessment->id, ['subject' => 'Hola', 'body' => '{{enlace}} {{contrasena}}'], $this->admin->id);
        $this->assertSame(['subject' => 'Hola', 'body' => '{{enlace}} {{contrasena}}'], Invitations::template($this->assessment->fresh()));
        $this->assertSame(['cambio' => 'plantilla de invitación'], AuditLog::where('action', 'assessment.editado')->sole()->metadata);
    }
}
