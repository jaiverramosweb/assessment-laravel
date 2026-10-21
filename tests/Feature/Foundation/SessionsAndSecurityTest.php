<?php

namespace Tests\Feature\Foundation;

use App\Auth\AdminSessions;
use App\Auth\ParticipantSessions;
use App\Auth\Passwords;
use App\Auth\SafeRedirect;
use App\Models\AdminSession;
use App\Models\Assessment;
use App\Models\AuthAttempt;
use App\Models\Participant;
use App\Security\Audit;
use App\Security\AuthRateLimiter;
use App\Security\SecretBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SessionsAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const HOUR = 3600 * 1000;

    public function test_admin_session_slides_after_half_its_life(): void
    {
        $admin = $this->createAdmin();
        $t0 = 1_000_000_000_000;
        $s = AdminSessions::create($admin->id, 12, $t0);

        // Before half its life: unchanged.
        $this->assertSame($t0 + 12 * self::HOUR, AdminSessions::validate($s['token'], 12, $t0 + 5 * self::HOUR)['expiresAt']);
        // After half its life: extended.
        $now = $t0 + 7 * self::HOUR;
        $this->assertSame($now + 12 * self::HOUR, AdminSessions::validate($s['token'], 12, $now)['expiresAt']);
    }

    public function test_admin_session_never_exceeds_seven_days(): void
    {
        $admin = $this->createAdmin();
        $t0 = 1_000_000_000_000;
        $s = AdminSessions::create($admin->id, 12, $t0);
        $now = $t0;
        while ($now < $t0 + 7 * 24 * self::HOUR - 7 * self::HOUR) {
            $now += 7 * self::HOUR;
            $this->assertNotNull(AdminSessions::validate($s['token'], 12, $now));
        }

        $this->assertNull(AdminSessions::validate($s['token'], 12, $t0 + 7 * 24 * self::HOUR));
        $this->assertSame(0, AdminSession::count());
    }

    public function test_expired_admin_session_is_deleted(): void
    {
        $admin = $this->createAdmin();
        $s = AdminSessions::create($admin->id, 12, 1000);

        $this->assertNull(AdminSessions::validate($s['token'], 12, 1000 + 12 * self::HOUR));
        $this->assertSame(0, AdminSession::count());
    }

    public function test_participant_session_validates_and_expires(): void
    {
        $participant = $this->participant();
        $s = ParticipantSessions::create($participant->id, 72, 1000);

        $this->assertSame($participant->id, ParticipantSessions::validate($s['token'], 72, 2000)['participantId']);
        $this->assertNull(ParticipantSessions::validate($s['token'], 72, 1000 + 72 * self::HOUR));
        $this->assertNull(ParticipantSessions::validate('otro', 72, 2000));
    }

    public function test_participant_cookie_is_scoped_to_the_assessment_path(): void
    {
        $cookie = ParticipantSessions::cookie('abc123', 'tok', 5_000_000_000_000);

        $this->assertSame('/a/abc123', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_safe_redirect_only_accepts_panel_paths(): void
    {
        $this->assertSame('/admin', SafeRedirect::admin(null));
        $this->assertSame('/admin/clientes', SafeRedirect::admin('/admin/clientes'));
        $this->assertSame('/admin?x=1', SafeRedirect::admin('/admin?x=1'));
        foreach (['https://malo.com', '//malo.com', '/admin\\malo', '/administrador-falso', '/admin/login', '/admin/logout', '/otra'] as $bad) {
            $this->assertSame('/admin', SafeRedirect::admin($bad), $bad);
        }
    }

    public function test_passwords_accept_hashes_made_by_the_original_app(): void
    {
        $nodeHash = '$argon2id$v=19$m=19456,t=2,p=1$EL+m6UCO0twXPQzBrkDAWA$0xWpjlWJ6HqbM8yuNLMxyVLw+6PM9Amq1+3ozPuC8Rg';

        $this->assertTrue(Passwords::verify($nodeHash, 'secreto-de-prueba'));
        $this->assertFalse(Passwords::verify($nodeHash, 'otro'));
        $this->assertFalse(Passwords::verify('', 'x'));
        $this->assertFalse(Passwords::verify('basura', 'x'));
        $this->assertStringStartsWith('$argon2id$v=19$m=19456,t=2,p=1$', Passwords::hash('x'));
    }

    public function test_secret_box_round_trip_and_tamper_detection(): void
    {
        $payload = SecretBox::encrypt('clave-de-acceso');

        $this->assertStringStartsWith('v1:', $payload);
        $this->assertSame('clave-de-acceso', SecretBox::decrypt($payload));
        $parts = explode(':', $payload);
        $parts[3] = base64_encode('otro-texto');
        $this->assertNull(SecretBox::decrypt(implode(':', $parts)));
        $this->assertNull(SecretBox::decrypt('v2:a:b:c'));
        $this->assertNull(SecretBox::decrypt('sin-formato'));
    }

    public function test_secret_box_reads_payloads_from_the_original_app(): void
    {
        config(['assessments.access_password_key' => str_repeat('0123456789abcdef', 4)]);
        SecretBox::reset();

        $this->assertSame('clave-de-acceso', SecretBox::decrypt('v1:FD3Rfx7xad5yJUlT:AGVeMsQiQiLrqggCulLqow==:cQZXWnvK+NcFx2eK/b+Z'));
    }

    public function test_secret_box_rejects_a_malformed_env_key(): void
    {
        config(['assessments.access_password_key' => 'corta']);
        SecretBox::reset();

        $this->expectExceptionMessage('ACCESS_PASSWORD_KEY debe tener 64 caracteres hexadecimales (32 bytes).');
        SecretBox::encrypt('x');
    }

    public function test_rate_limiter_window_resets_and_lock_expires(): void
    {
        $keys = AuthRateLimiter::limitKeys('prueba', '1.2.3.4', 'Ana@Empresa.com ');
        $this->assertSame('prueba:email:ana@empresa.com', $keys[1]['key']);

        $t = 1_000_000;
        for ($i = 0; $i < 4; $i++) {
            $this->assertNull(AuthRateLimiter::recordFailure($keys, $t));
        }
        // A new window starts after 15 minutes without locking.
        $later = $t + 16 * 60 * 1000;
        $this->assertNull(AuthRateLimiter::recordFailure($keys, $later));
        $this->assertSame(1, AuthAttempt::find($keys[1]['key'])->failures);

        for ($i = 0; $i < 3; $i++) {
            AuthRateLimiter::recordFailure($keys, $later);
        }
        $until = AuthRateLimiter::recordFailure($keys, $later);
        $this->assertSame($later + 15 * 60 * 1000, $until);
        $this->assertSame($until, AuthRateLimiter::lockedUntil($keys, $later + 1));
        $this->assertNull(AuthRateLimiter::lockedUntil($keys, $until + 1));
    }

    public function test_participant_keys_count_email_per_ip(): void
    {
        $keys = AuthRateLimiter::participantLimitKeys('acceso', '9.9.9.9', 'Ana@x.com');

        $this->assertSame('acceso:ip:9.9.9.9', $keys[0]['key']);
        $this->assertSame(50, $keys[0]['policy']['maxFailures']);
        $this->assertSame('acceso:email:ana@x.com:ip:9.9.9.9', $keys[1]['key']);
    }

    public function test_lock_message_rounds_minutes_up(): void
    {
        $this->assertSame('Demasiados intentos fallidos. Intente de nuevo en 1 minuto.', AuthRateLimiter::lockMessage(1000, 0));
        $this->assertSame('Demasiados intentos fallidos. Intente de nuevo en 2 minutos.', AuthRateLimiter::lockMessage(61_000, 0));
    }

    public function test_purge_keeps_active_locks(): void
    {
        $now = 100 * 24 * self::HOUR;
        AuthAttempt::create(['key' => 'viejo', 'failures' => 1, 'window_start' => 0, 'locked_until' => null]);
        AuthAttempt::create(['key' => 'bloqueado', 'failures' => 0, 'window_start' => 0, 'locked_until' => $now + 1000]);
        AuthAttempt::create(['key' => 'reciente', 'failures' => 1, 'window_start' => $now, 'locked_until' => null]);

        AuthRateLimiter::purgeStale(24 * self::HOUR, $now);

        $this->assertEqualsCanonicalizing(['bloqueado', 'reciente'], AuthAttempt::pluck('key')->all());
    }

    public function test_audit_rejects_unknown_actions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Audit::log(null, 'accion.inventada');
    }

    private function participant(): Participant
    {
        $assessment = Assessment::create([
            'slug' => 'abc123', 'title' => 'Prueba', 'starts_at' => 0, 'ends_at' => 1,
        ]);

        return Participant::create(['assessment_id' => $assessment->id, 'name' => 'Ana', 'email' => 'ana@x.com']);
    }
}
