<?php

namespace Tests\Feature\Foundation;

use App\Auth\AdminSessions;
use App\Auth\Tokens;
use App\Models\AdminSession;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'admin'])->get('/admin/prueba', fn () => 'panel');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Login'));
    }

    public function test_valid_credentials_create_a_session_and_redirect(): void
    {
        $admin = $this->createAdmin();

        $response = $this->post('/admin/login', ['email' => ' ANA@empresa.com ', 'password' => 'clave-segura-123']);

        $response->assertStatus(303)->assertRedirect('/admin');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === AdminSessions::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        // Only the SHA-256 of the token is stored.
        $this->assertNotNull(AdminSession::find(Tokens::hash($cookie->getValue())));
        $this->assertNotNull($admin->fresh()->last_login_at);
        $this->assertTrue(AuditLog::where('action', 'admin.login')->where('admin_id', $admin->id)->exists());
    }

    public function test_wrong_password_and_unknown_email_get_the_same_message(): void
    {
        $this->createAdmin();

        $this->from('/admin/login')->post('/admin/login', ['email' => 'ana@empresa.com', 'password' => 'otra-clave'])
            ->assertStatus(303)
            ->assertSessionHasErrors(['form' => 'Email o contraseña incorrectos.']);
        $this->from('/admin/login')->post('/admin/login', ['email' => 'nadie@empresa.com', 'password' => 'otra-clave'])
            ->assertSessionHasErrors(['form' => 'Email o contraseña incorrectos.']);

        $this->assertSame(2, AuditLog::where('action', 'admin.login_fallido')->count());
        $this->assertSame(0, AdminSession::count());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->from('/admin/login')->post('/admin/login', ['email' => 'no-es-email', 'password' => 'x'])
            ->assertSessionHasErrors(['form' => 'Escriba un email válido.']);
        $this->from('/admin/login')->post('/admin/login', ['email' => 'ana@empresa.com', 'password' => ''])
            ->assertSessionHasErrors(['form' => 'Escriba la contraseña.']);
    }

    public function test_email_is_locked_after_five_failures(): void
    {
        $this->createAdmin();
        for ($i = 0; $i < 4; $i++) {
            $this->post('/admin/login', ['email' => 'ana@empresa.com', 'password' => 'mala'])
                ->assertSessionHasErrors(['form' => 'Email o contraseña incorrectos.']);
        }

        $this->post('/admin/login', ['email' => 'ana@empresa.com', 'password' => 'mala'])
            ->assertSessionHasErrors(['form' => 'Demasiados intentos fallidos. Intente de nuevo en 15 minutos.']);
        // Even the right password is refused while locked.
        $this->post('/admin/login', ['email' => 'ana@empresa.com', 'password' => 'clave-segura-123'])
            ->assertSessionHasErrors(['form' => 'Demasiados intentos fallidos. Intente de nuevo en 15 minutos.']);
        $this->assertSame(0, AdminSession::count());
    }

    public function test_destination_is_honored_only_inside_the_panel(): void
    {
        $this->createAdmin();
        $this->post('/admin/login?destino='.rawurlencode('/admin/clientes?x=1'), ['email' => 'ana@empresa.com', 'password' => 'clave-segura-123'])
            ->assertRedirect('/admin/clientes?x=1');

        $this->post('/admin/login?destino='.rawurlencode('https://malo.com'), ['email' => 'ana@empresa.com', 'password' => 'clave-segura-123'])
            ->assertRedirect('/admin');
    }

    public function test_panel_requires_a_session_and_keeps_the_destination(): void
    {
        $this->get('/admin/prueba?x=1')
            ->assertStatus(303)
            ->assertRedirect('/admin/login?destino='.rawurlencode('/admin/prueba?x=1'));
    }

    public function test_panel_pages_are_not_cached(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/prueba')->assertOk()->assertSee('panel')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_logged_in_admin_skips_the_login_page(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/login')->assertRedirect('/admin');
    }

    public function test_expired_cookie_is_removed(): void
    {
        $this->withUnencryptedCookie(AdminSessions::COOKIE, 'token-inventado');

        $response = $this->get('/admin/prueba');

        $response->assertRedirect();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === AdminSessions::COOKIE);
        $this->assertTrue($cookie->isCleared());
    }

    public function test_logout_closes_the_session_and_is_post_only(): void
    {
        $admin = $this->actingAsAdmin();
        $this->get('/admin/logout')->assertStatus(405);

        $response = $this->post('/admin/logout');

        $response->assertStatus(303)->assertRedirect('/admin/login');
        $this->assertSame(0, AdminSession::count());
        $this->assertTrue(AuditLog::where('action', 'admin.logout')->where('admin_id', $admin->id)->exists());
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_unknown_page_renders_the_error_page(): void
    {
        $this->get('/no-existe')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404));
    }
}
