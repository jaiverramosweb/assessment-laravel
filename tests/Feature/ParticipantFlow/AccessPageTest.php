<?php

namespace Tests\Feature\ParticipantFlow;

use App\Auth\ParticipantSessions;
use App\Models\Participant;
use App\Models\ParticipantSession;
use App\Models\Submission;
use Inertia\Testing\AssertableInertia as Assert;

/** HTTP behavior of /a/{slug} (access form) and /a/{slug}/entregado. */
class AccessPageTest extends ParticipantFlowTestCase
{
    private function url(string $suffix = ''): string
    {
        return '/a/'.$this->assessment->slug.$suffix;
    }

    private function enterForm(array $data, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->from($this->url())->post($this->url(), $data);
    }

    public function test_unknown_malformed_and_draft_assessments_are_404(): void
    {
        $message = 'Este assessment no existe o no está disponible.';
        $this->get('/a/noexiste123')->assertNotFound()
            ->assertInertia(fn (Assert $p) => $p->component('Error')->where('status', 404)->where('message', $message));
        $this->get('/a/NO-VALIDO')->assertNotFound();
        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co'])->assertRedirect(); // sanity: published works

        $this->assessment->update(['status' => 'borrador']);
        $this->get($this->url())->assertNotFound();
        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co'])->assertNotFound();
        $this->get($this->url('/entregado'))->assertNotFound();
    }

    public function test_access_page_shows_only_public_data(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Participant/Access')
                ->where('availability', 'abierto')
                ->where('assessment.title', 'Seguridad')
                ->where('assessment.accessMode', 'invitados')
                ->where('assessment.startsAt', self::STARTS)
                ->where('assessment.endsAt', self::ENDS)
                ->where('assessment.timezone', 'America/Bogota')
                ->where('assessment.logoUrl', null)
                ->where('form', null)
                ->missing('assessment.access_password_hash')
                ->missing('assessment.id'));
    }

    public function test_archived_and_out_of_window_assessments_show_their_availability(): void
    {
        $this->assessment->update(['status' => 'archivado']);
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('availability', 'no_disponible'));

        $this->assessment->update(['status' => 'publicado', 'ends_at' => self::NOW]);
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('availability', 'cerrado'));

        $this->assessment->update(['ends_at' => self::ENDS, 'starts_at' => self::NOW + 1]);
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('availability', 'no_iniciado'));
    }

    public function test_successful_entry_sets_the_path_scoped_cookie_and_redirects_to_answer(): void
    {
        $response = $this->enterForm(['password' => self::PASSWORD, 'email' => '  ANA@x.co ']);

        $response->assertStatus(303)->assertRedirect($this->url('/responder'));
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === ParticipantSessions::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertSame('/a/'.$this->assessment->slug, $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame(1, ParticipantSession::count());
        $this->assertSame(1, Submission::count());
    }

    public function test_access_page_with_a_valid_session_continues_to_answer(): void
    {
        $ctx = $this->login();
        $this->withUnencryptedCookie(ParticipantSessions::COOKIE, $ctx['token'])
            ->get($this->url())
            ->assertStatus(303)->assertRedirect($this->url('/responder'));
    }

    private function assertFormError(string $message, ?bool $needName = false): void
    {
        $this->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('form.error', $message)
            ->where('form.needName', $needName)
            ->etc());
    }

    public function test_validation_messages_are_the_original_ones(): void
    {
        $this->enterForm(['email' => 'ana@x.co'])->assertStatus(303)->assertRedirect($this->url());
        $this->assertFormError('Escriba la contraseña de acceso.');

        $this->enterForm(['password' => str_repeat('x', 101), 'email' => 'ana@x.co']);
        $this->assertFormError('La contraseña es demasiado larga.');

        $this->enterForm(['password' => self::PASSWORD]);
        $this->assertFormError('Escriba un email.');

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'no-es-email']);
        $this->assertFormError('Escriba un email válido.');

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co', 'name' => 'Ver www.sitio.com']);
        $this->assertFormError('El nombre no puede contener enlaces.');

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co', 'name' => 'A']);
        $this->assertFormError('El nombre debe tener al menos 2 caracteres.');

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co', 'name' => str_repeat('n', 151)]);
        $this->assertFormError('El nombre no puede superar 150 caracteres.');

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co', 'name' => "Ana\nPérez"]);
        $this->assertFormError('El nombre no puede tener saltos de línea.');
    }

    public function test_entry_failures_show_the_original_messages_and_keep_the_typed_values(): void
    {
        $this->enterForm(['password' => 'mala', 'email' => 'ana@x.co']);
        $this->get($this->url())->assertInertia(fn (Assert $p) => $p
            ->where('form.error', 'La contraseña de acceso no es correcta.')
            ->where('form.values.email', 'ana@x.co')
            ->etc());

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'intruso@x.co']);
        $this->assertFormError('Este email no está en la lista de invitados. Use el mismo correo en el que recibió la invitación.');
        $this->assertSame(0, ParticipantSession::count());
    }

    public function test_rate_limit_message(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->enterForm(['password' => 'mala', 'email' => 'ana@x.co']);
        }
        $this->assertFormError('Demasiados intentos fallidos. Intente de nuevo en 15 minutos.');

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co']);
        $this->assertFormError('Demasiados intentos fallidos. Intente de nuevo en 15 minutos.');
        $this->assertSame(0, ParticipantSession::count());
    }

    public function test_out_of_window_and_archived_messages(): void
    {
        $this->assessment->update(['starts_at' => self::NOW + self::HOUR]);
        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co']);
        $this->assertFormError("Aún no está disponible. Podrá ingresar desde el 5 de noviembre de 2026 a las 11:00\u{00A0}a.\u{00A0}m..");

        $this->assessment->update(['starts_at' => self::STARTS, 'ends_at' => self::NOW]);
        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co']);
        $this->assertFormError('Este assessment ya cerró.');

        $this->assessment->update(['ends_at' => self::ENDS, 'status' => 'archivado']);
        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co']);
        $this->assertFormError('Este assessment no está disponible.');
    }

    public function test_open_mode_first_entry_asks_for_the_name_then_registers(): void
    {
        $this->setUpAssessment('abierto');

        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->where('assessment.accessMode', 'abierto')->etc());
        $this->enterForm(['password' => self::PASSWORD, 'email' => 'nuevo@x.co']);
        $this->assertFormError('Es su primer ingreso: escriba su nombre para registrarse.', true);

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'nuevo@x.co', 'name' => ' Persona Nueva '])
            ->assertRedirect($this->url('/responder'));
        $p = Participant::where('email', 'nuevo@x.co')->first();
        $this->assertSame('Persona Nueva', $p->name);
        $this->assertSame('abierto', $p->source);
    }

    public function test_already_submitted_message(): void
    {
        $ctx = $this->login();
        Submission::where('id', $ctx['submission']->id)->update(['submitted_at' => self::STARTS + self::HOUR]);

        $this->enterForm(['password' => self::PASSWORD, 'email' => 'ana@x.co']);
        $this->assertFormError("Este email ya entregó sus respuestas el 1 de noviembre de 2026 a las 9:00\u{00A0}a.\u{00A0}m.. Solo se permite una entrega por persona.");
    }

    public function test_delivered_page(): void
    {
        $this->get($this->url('/entregado'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Participant/Delivered')
                ->where('title', 'Seguridad')
                ->where('logoUrl', null)
                ->has('themeStyle'));
        $this->get('/a/noexiste123/entregado')->assertNotFound();
    }
}
