<?php

namespace Tests\Feature\Foundation;

use App\Auth\AdminSessions;
use App\Auth\Passwords;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'otra-clave-segura-456';

    public function test_requires_an_admin_session(): void
    {
        $this->get('/admin/usuarios')->assertRedirect();
        $this->get('/admin/cuenta')->assertRedirect();
        $this->post('/admin/usuarios', ['name' => 'David', 'email' => 'david@x.co', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect();
        $this->assertSame(0, AdminUser::count());
    }

    public function test_index_lists_admins_with_status_and_self_flag(): void
    {
        $me = $this->actingAsAdmin();
        $lida = $this->createAdmin('lida@x.co');
        $lida->update(['name' => 'Lida', 'disabled_at' => 1000]);

        $this->get('/admin/usuarios')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->has('users', 2)
                ->where('users.0.email', 'ana@empresa.com')
                ->where('users.0.isSelf', true)
                ->where('users.0.active', true)
                ->where('users.1.email', 'lida@x.co')
                ->where('users.1.isSelf', false)
                ->where('users.1.active', false)
                ->missing('users.0.password_hash'));
        $this->assertNotNull($me);
    }

    public function test_create_admin_who_can_then_log_in(): void
    {
        $me = $this->actingAsAdmin();

        $this->from('/admin/usuarios')->post('/admin/usuarios', [
            'name' => ' David Rojas ', 'email' => ' DAVID@Empresa.co ',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(303)->assertRedirect('/admin/usuarios')
            ->assertSessionHas('success', 'Usuario creado: david@empresa.co. Ya puede ingresar con la contraseña que le asignó.');

        $david = AdminUser::where('email', 'david@empresa.co')->sole();
        $this->assertSame('David Rojas', $david->name);
        $this->assertNull($david->disabled_at);
        $this->assertTrue(Passwords::verify($david->password_hash, self::NEW_PASSWORD));
        $log = AuditLog::where('action', 'admin.creado')->sole();
        $this->assertSame($me->id, $log->admin_id);
        $this->assertSame(['email' => 'david@empresa.co', 'origen' => 'panel'], $log->metadata);

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'david@empresa.co', 'password' => self::NEW_PASSWORD])
            ->assertRedirect('/admin');
    }

    public function test_create_validation_and_duplicate_email(): void
    {
        $this->actingAsAdmin();

        $this->from('/admin/usuarios')->post('/admin/usuarios', [
            'name' => 'D', 'email' => 'no-es-email', 'password' => 'corta', 'password_confirmation' => 'corta',
        ])->assertSessionHasErrors([
            'name' => 'El nombre es muy corto.',
            'email' => 'Escriba un email válido.',
            'password' => 'La contraseña debe tener al menos 10 caracteres.',
        ]);

        $this->from('/admin/usuarios')->post('/admin/usuarios', [
            'name' => 'David', 'email' => 'david@x.co', 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'distinta-clave-789',
        ])->assertSessionHasErrors(['password' => 'Las contraseñas no coinciden.']);

        $this->from('/admin/usuarios')->post('/admin/usuarios', [
            'name' => 'Otra Ana', 'email' => 'ANA@empresa.com', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['email' => 'Ya existe un usuario con el email ana@empresa.com.']);

        $this->assertSame(1, AdminUser::count());
        $this->assertFalse(AuditLog::where('action', 'admin.creado')->exists());
    }

    public function test_reset_another_admins_password_closes_their_sessions(): void
    {
        $me = $this->actingAsAdmin();
        $lida = $this->createAdmin('lida@x.co');
        AdminSessions::create($lida->id, 12);

        $this->from('/admin/usuarios')->post("/admin/usuarios/{$lida->id}/password", [
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(303)->assertSessionHas('success', 'Contraseña de lida@x.co cambiada. Sus sesiones abiertas se cerraron.');

        $this->assertTrue(Passwords::verify($lida->fresh()->password_hash, self::NEW_PASSWORD));
        $this->assertSame(0, AdminSession::where('admin_id', $lida->id)->count());
        $log = AuditLog::where('action', 'admin.password_cambiada')->sole();
        $this->assertSame($me->id, $log->admin_id);
        $this->assertSame($lida->id, $log->entity_id);
        $this->assertSame(['origen' => 'panel'], $log->metadata);

        // Validation: nothing changes.
        $this->from('/admin/usuarios')->post("/admin/usuarios/{$lida->id}/password", ['password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertSessionHasErrors(['password' => 'La contraseña debe tener al menos 10 caracteres.']);
        $this->assertTrue(Passwords::verify($lida->fresh()->password_hash, self::NEW_PASSWORD));
    }

    public function test_own_password_is_not_reset_from_the_users_list(): void
    {
        $me = $this->actingAsAdmin();

        $this->from('/admin/usuarios')->post("/admin/usuarios/{$me->id}/password", [
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHas('error', 'Para cambiar su propia contraseña use "Mi cuenta".');
        $this->assertTrue(Passwords::verify($me->fresh()->password_hash, 'clave-segura-123'));

        $this->from('/admin/usuarios')->post('/admin/usuarios/00000000-0000-0000-0000-000000000000/password', [
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHas('error', 'El usuario no existe.');
    }

    public function test_deactivate_blocks_login_and_closes_sessions_then_reactivate(): void
    {
        $me = $this->actingAsAdmin();
        $lida = $this->createAdmin('lida@x.co');
        AdminSessions::create($lida->id, 12);

        $this->from('/admin/usuarios')->post("/admin/usuarios/{$lida->id}/desactivar")
            ->assertStatus(303)->assertSessionHas('success', 'Usuario lida@x.co desactivado. Ya no puede ingresar.');
        $this->assertNotNull($lida->fresh()->disabled_at);
        $this->assertSame(0, AdminSession::where('admin_id', $lida->id)->count());
        $this->assertSame($me->id, AuditLog::where('action', 'admin.desactivado')->sole()->admin_id);

        // Same message as a wrong password: it does not reveal that the account exists.
        $this->post('/admin/logout');
        $this->from('/admin/login')->post('/admin/login', ['email' => 'lida@x.co', 'password' => 'clave-segura-123'])
            ->assertSessionHasErrors(['form' => 'Email o contraseña incorrectos.']);

        $this->actingAsAdmin($me);
        $this->from('/admin/usuarios')->post("/admin/usuarios/{$lida->id}/activar")
            ->assertSessionHas('success', 'Usuario lida@x.co activado.');
        $this->assertNull($lida->fresh()->disabled_at);
        $this->assertTrue(AuditLog::where('action', 'admin.reactivado')->exists());

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'lida@x.co', 'password' => 'clave-segura-123'])->assertRedirect('/admin');
    }

    public function test_cannot_deactivate_yourself(): void
    {
        $me = $this->actingAsAdmin();

        $this->from('/admin/usuarios')->post("/admin/usuarios/{$me->id}/desactivar")
            ->assertSessionHas('error', 'No puede desactivar su propio usuario.');
        $this->assertNull($me->fresh()->disabled_at);
        $this->get('/admin/usuarios')->assertOk();
    }

    public function test_session_of_a_deactivated_admin_stops_working(): void
    {
        $lida = $this->createAdmin('lida@x.co');
        $lida->update(['disabled_at' => 1000]);
        // A session that survived (e.g. created before the column existed) is rejected.
        $this->actingAsAdmin($lida);

        $this->get('/admin/usuarios')->assertRedirect();
    }

    public function test_change_own_password_keeps_current_session_and_closes_the_others(): void
    {
        $me = $this->actingAsAdmin();
        $other = AdminSessions::create($me->id, 12);

        $this->get('/admin/cuenta')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Account')
            ->where('admin.email', 'ana@empresa.com'));

        $this->from('/admin/cuenta')->post('/admin/cuenta/password', [
            'current_password' => 'clave-segura-123', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(303)->assertSessionHas('success', 'Contraseña cambiada. Sus otras sesiones se cerraron.');

        $this->assertTrue(Passwords::verify($me->fresh()->password_hash, self::NEW_PASSWORD));
        $this->assertNull(AdminSession::find(\App\Auth\Tokens::hash($other['token'])));
        $this->assertSame(1, AdminSession::where('admin_id', $me->id)->count());
        $this->get('/admin/cuenta')->assertOk();
        $log = AuditLog::where('action', 'admin.password_cambiada')->sole();
        $this->assertSame($me->id, $log->admin_id);
        $this->assertSame(['origen' => 'mi cuenta'], $log->metadata);
    }

    public function test_change_own_password_validation(): void
    {
        $me = $this->actingAsAdmin();

        $this->from('/admin/cuenta')->post('/admin/cuenta/password', [
            'current_password' => 'incorrecta-000', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['current_password' => 'La contraseña actual no es correcta.']);

        $this->from('/admin/cuenta')->post('/admin/cuenta/password', [
            'current_password' => 'clave-segura-123', 'password' => 'corta', 'password_confirmation' => 'corta',
        ])->assertSessionHasErrors(['password' => 'La contraseña debe tener al menos 10 caracteres.']);

        $this->assertTrue(Passwords::verify($me->fresh()->password_hash, 'clave-segura-123'));
        $this->assertFalse(AuditLog::where('action', 'admin.password_cambiada')->exists());
    }

    public function test_wrong_current_password_is_rate_limited(): void
    {
        $this->actingAsAdmin();
        for ($i = 0; $i < 5; $i++) {
            $this->from('/admin/cuenta')->post('/admin/cuenta/password', [
                'current_password' => "incorrecta-{$i}00", 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
            ]);
        }

        $response = $this->from('/admin/cuenta')->post('/admin/cuenta/password', [
            'current_password' => 'clave-segura-123', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ]);
        $response->assertSessionHasErrors('current_password');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('current_password'));
    }
}
