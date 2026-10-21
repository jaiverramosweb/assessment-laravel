<?php

namespace Tests\Feature\Clients;

use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Clients\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LogoTest extends TestCase
{
    use RefreshDatabase;

    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    private static function png(): string
    {
        return base64_decode(ClientServiceTest::PNG_BASE64);
    }

    private function clientWithLogo(string $adminId): Client
    {
        $c = ClientService::create('Acme', $adminId);
        ClientService::setLogo($c->id, self::png(), $adminId);

        return $c->fresh();
    }

    public function test_uploading_a_png_logo(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        $url = "/admin/clientes/{$c->id}";

        $this->from($url)->post("{$url}/logo", ['logo' => UploadedFile::fake()->createWithContent('logo.png', self::png())])
            ->assertStatus(303)->assertRedirect($url)
            ->assertSessionHas('success', 'Logo actualizado.');

        $fresh = $c->fresh();
        $this->assertSame('image/png', $fresh->logo_mime);
        $this->assertNotNull($fresh->logo_updated_at);
        $this->assertSame(self::png(), ClientService::readLogo($c->id)['data']);
        $this->assertSame(['cambio' => 'logo actualizado'], AuditLog::where('action', 'cliente.editado')->first()->metadata);
    }

    public function test_the_logo_type_comes_from_the_content_not_the_name(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        $url = "/admin/clientes/{$c->id}";

        // An SVG renamed to .png is still rejected.
        $svg = UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->from($url)->post("{$url}/logo", ['logo' => $svg])
            ->assertStatus(303)
            ->assertSessionHasErrors(['logo' => 'Formato no soportado. Use una imagen PNG, JPG o WebP.']);

        // A JPEG named .png is stored as JPEG.
        $this->from($url)->post("{$url}/logo", ['logo' => UploadedFile::fake()->createWithContent('logo.png', "\xFF\xD8\xFF\xE0jpeg")]);
        $this->assertSame('image/jpeg', $c->fresh()->logo_mime);
    }

    public function test_missing_empty_and_oversized_logos_are_rejected(): void
    {
        $admin = $this->actingAsAdmin();
        $c = ClientService::create('Acme', $admin->id);
        $url = "/admin/clientes/{$c->id}";

        $this->from($url)->post("{$url}/logo", [])
            ->assertSessionHasErrors(['logo' => 'Seleccione una imagen.']);
        $this->from($url)->post("{$url}/logo", ['logo' => UploadedFile::fake()->createWithContent('logo.png', '')])
            ->assertSessionHasErrors(['logo' => 'Seleccione una imagen.']);
        $big = UploadedFile::fake()->createWithContent('logo.png', self::png().str_repeat("\0", 2 * 1024 * 1024));
        $this->from($url)->post("{$url}/logo", ['logo' => $big])
            ->assertSessionHasErrors(['logo' => 'El logo no puede superar 2 MB.']);

        $this->assertNull($c->fresh()->logo_mime);
        $this->assertFalse(AuditLog::where('action', 'cliente.editado')->exists());
    }

    public function test_removing_the_logo(): void
    {
        $admin = $this->actingAsAdmin();
        $c = $this->clientWithLogo($admin->id);
        $url = "/admin/clientes/{$c->id}";

        $this->from($url)->delete("{$url}/logo")
            ->assertStatus(303)->assertRedirect($url)
            ->assertSessionHas('success', 'Logo eliminado.');

        $this->assertNull($c->fresh()->logo_mime);
        $this->assertNull($c->fresh()->logo_updated_at);
        $this->assertNull(ClientService::readLogo($c->id));
    }

    public function test_admin_logo_is_served_with_its_type_and_a_sandbox(): void
    {
        $admin = $this->actingAsAdmin();
        $c = $this->clientWithLogo($admin->id);

        $response = $this->get("/admin/clientes/{$c->id}/logo?v={$c->logo_updated_at}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Security-Policy', self::CSP)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::png(), $response->getContent());
    }

    public function test_admin_logo_is_not_public_and_404_without_logo(): void
    {
        $admin = $this->createAdmin();
        $c = $this->clientWithLogo($admin->id);
        $this->get("/admin/clientes/{$c->id}/logo")->assertStatus(303);

        $this->actingAsAdmin($admin);
        $empty = ClientService::create('Sin logo', $admin->id);
        $this->get("/admin/clientes/{$empty->id}/logo")->assertNotFound();
        $this->get('/admin/clientes/xyz/logo')->assertNotFound();
    }

    public function test_public_logo_of_a_published_assessment(): void
    {
        $admin = $this->createAdmin();
        $c = $this->clientWithLogo($admin->id);
        $a = $this->createAssessment(['client_id' => $c->id, 'status' => 'publicado']);

        $versioned = $this->get("/a/{$a->slug}/logo?v={$c->logo_updated_at}");
        $versioned->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Security-Policy', self::CSP)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::png(), $versioned->getContent());
        $this->assertCacheControl('public, max-age=31536000, immutable', $versioned->headers->get('Cache-Control'));

        $plain = $this->get("/a/{$a->slug}/logo");
        $plain->assertOk();
        // Symfony adds "private" to a bare no-cache; it only forbids shared caches.
        $this->assertCacheControl('no-cache, private', $plain->headers->get('Cache-Control'));

        // Archived assessments keep showing it (only drafts are hidden).
        $a->update(['status' => 'archivado']);
        $this->get("/a/{$a->slug}/logo")->assertOk();
    }

    private function assertCacheControl(string $expected, ?string $actual): void
    {
        $parts = fn (?string $v) => collect(explode(',', (string) $v))->map(fn ($p) => trim($p))->filter()->sort()->values()->all();
        $this->assertSame($parts($expected), $parts($actual));
    }

    public function test_public_logo_is_not_served_for_drafts_unknown_slugs_or_clients_without_logo(): void
    {
        $admin = $this->createAdmin();
        $c = $this->clientWithLogo($admin->id);

        $draft = $this->createAssessment(['client_id' => $c->id, 'status' => 'borrador']);
        $this->get("/a/{$draft->slug}/logo")->assertNotFound();

        $this->get('/a/noexiste123/logo')->assertNotFound();
        $this->get('/a/MAL-SLUG/logo')->assertNotFound();

        $noLogo = ClientService::create('Sin logo', $admin->id);
        $a = $this->createAssessment(['client_id' => $noLogo->id, 'status' => 'publicado']);
        $this->get("/a/{$a->slug}/logo")->assertNotFound();
        $free = $this->createAssessment(['status' => 'publicado']);
        $this->get("/a/{$free->slug}/logo")->assertNotFound();
    }
}
