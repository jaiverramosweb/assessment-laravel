<?php

namespace Tests\Feature\Clients;

use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Clients\ClientInUseError;
use App\Services\Clients\ClientService;
use App\Services\Clients\LogoError;
use App\Services\Shared\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Port of the original src/lib/server/services/clients.spec.ts. */
class ClientServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Minimal valid PNG (1x1). */
    public const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createAdmin()->id;
    }

    private static function png(): string
    {
        return base64_decode(self::PNG_BASE64);
    }

    public function test_detects_the_logo_type_by_its_content_and_rejects_the_rest(): void
    {
        $this->assertSame('image/png', ClientService::detectLogoType(self::png()));
        $this->assertSame('image/jpeg', ClientService::detectLogoType("\xFF\xD8\xFF\xE0\x00\x00"));
        $this->assertSame('image/webp', ClientService::detectLogoType("RIFF\x00\x00\x00\x00WEBPVP8 "));

        $this->assertLogoError('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'Formato no soportado. Use una imagen PNG, JPG o WebP.');
        $this->assertLogoError('', 'Seleccione una imagen.');
        $this->assertLogoError(self::png().str_repeat("\0", 2 * 1024 * 1024 + 1), 'El logo no puede superar 2 MB.');
    }

    private function assertLogoError(string $data, string $message): void
    {
        try {
            ClientService::detectLogoType($data);
            $this->fail('LogoError expected');
        } catch (LogoError $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_assessments_inherit_the_client_logo_and_colors(): void
    {
        $c = ClientService::create('Acme S.A.', $this->adminId);
        ClientService::setLogo($c->id, self::png(), $this->adminId);
        ClientService::setTheme($c->id, ['primary' => '#1a2b5c', 'accent' => '#c2410c'], $this->adminId);

        $a = $this->createAssessment(['client_id' => $c->id]);
        $brand = Brand::forParticipant($a->slug, $a->client_id);
        $this->assertMatchesRegularExpression('#^/a/'.$a->slug.'/logo\?v=\d+$#', $brand['logoUrl']);
        $this->assertStringContainsString('--color-ink-950: #1a2b5c', $brand['themeStyle']);
        $this->assertStringContainsString('--color-brand-600: #c2410c', $brand['themeStyle']);
        $this->assertSame(self::png(), ClientService::readLogo($a->client_id)['data']);

        // Without a client the platform colors are used.
        $free = $this->createAssessment();
        $this->assertSame(['logoUrl' => null, 'themeStyle' => null], Brand::forParticipant($free->slug, $free->client_id));

        ClientService::setLogo($c->id, null, $this->adminId);
        ClientService::setTheme($c->id, null, $this->adminId);
        $this->assertNull(Brand::get($c->id)['logo_mime']);
        $this->assertNull(Brand::get($c->id)['theme_primary']);
        $this->assertSame(['logoUrl' => null, 'themeStyle' => null], Brand::forParticipant($a->slug, $a->client_id));
        $this->assertNull(ClientService::readLogo($c->id));
    }

    public function test_logo_bytes_round_trip_as_a_blob(): void
    {
        $c = ClientService::create('Acme', $this->adminId);
        // Every byte value, including NUL and invalid UTF-8 sequences.
        $bytes = self::png().implode('', array_map('chr', range(0, 255)));
        ClientService::setLogo($c->id, $bytes, $this->adminId);

        $this->assertSame($bytes, ClientService::readLogo($c->id)['data']);
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame('blob', DB::scalar('select typeof(logo_data) from clients where id = ?', [$c->id]));
        }
        $this->assertSame(strlen($bytes), (int) DB::scalar('select length(logo_data) from clients where id = ?', [$c->id]));
        // The bytes never travel in serialized models.
        $this->assertArrayNotHasKey('logo_data', Client::find($c->id)->toArray());
    }

    public function test_a_logo_of_the_maximum_size_is_stored_whole(): void
    {
        $c = ClientService::create('Acme', $this->adminId);
        $png = self::png();
        $bytes = $png.str_repeat("\x00", ClientService::LOGO_MAX_BYTES - strlen($png));
        ClientService::setLogo($c->id, $bytes, $this->adminId);

        $this->assertSame(strlen($bytes), strlen(ClientService::readLogo($c->id)['data']));
    }

    public function test_names_are_unique_case_insensitively_and_assessments_are_counted(): void
    {
        $c = ClientService::create('Acme', $this->adminId);
        $this->assertTrue(ClientService::nameTaken('ACME'));
        $this->assertFalse(ClientService::nameTaken('acme', $c->id));
        $this->createAssessment(['client_id' => $c->id]);
        ClientService::create('beta', $this->adminId);

        $list = ClientService::list();
        $this->assertSame(['Acme', 'beta'], array_column($list, 'name'));
        $this->assertSame([1, 0], array_column($list, 'assessmentCount'));
    }

    public function test_only_a_client_without_assessments_is_deleted_and_it_is_audited(): void
    {
        $c = ClientService::create('Acme', $this->adminId);
        $this->createAssessment(['client_id' => $c->id]);
        try {
            ClientService::delete($c, $this->adminId);
            $this->fail('ClientInUseError expected');
        } catch (ClientInUseError $e) {
            $this->assertSame('El cliente tiene assessments. Elimínelos o asígnelos a otro cliente primero.', $e->getMessage());
        }
        $this->assertNotNull(Client::find($c->id));

        $empty = ClientService::create('Vacío', $this->adminId);
        ClientService::delete($empty, $this->adminId);
        $this->assertNull(Client::find($empty->id));
        $log = AuditLog::where('action', 'cliente.eliminado')->first();
        $this->assertSame($empty->id, $log->entity_id);
        $this->assertSame(['nombre' => 'Vacío'], $log->metadata);
    }

    public function test_changes_are_audited(): void
    {
        $c = ClientService::create('Acme', $this->adminId, '10.0.0.1');
        ClientService::rename($c->id, 'Acme 2', $this->adminId);
        ClientService::setTheme($c->id, ['primary' => '#111111', 'accent' => '#222222'], $this->adminId);
        ClientService::setTheme($c->id, null, $this->adminId);
        ClientService::setLogo($c->id, self::png(), $this->adminId);
        ClientService::setLogo($c->id, null, $this->adminId);

        $created = AuditLog::where('action', 'cliente.creado')->first();
        $this->assertSame(['nombre' => 'Acme'], $created->metadata);
        $this->assertSame('cliente', $created->entity_type);
        $this->assertSame('10.0.0.1', $created->ip);
        $this->assertSame([
            ['nombre' => 'Acme 2'],
            ['cambio' => 'colores', 'principal' => '#111111', 'acento' => '#222222'],
            ['cambio' => 'colores de la plataforma'],
            ['cambio' => 'logo actualizado'],
            ['cambio' => 'logo eliminado'],
        ], AuditLog::where('action', 'cliente.editado')->orderBy('id')->get()->pluck('metadata')->all());
    }

    public function test_name_and_theme_validation_messages(): void
    {
        $this->assertSame(['Acme', null], ClientService::parseName('  Acme '));
        $this->assertSame('El nombre debe tener al menos 2 caracteres.', ClientService::parseName(' A ')[1]);
        $this->assertSame('Nombre: máximo 120 caracteres.', ClientService::parseName(str_repeat('a', 121))[1]);
        $this->assertSame('El nombre no puede tener saltos de línea.', ClientService::parseName("Ac\nme")[1]);

        [$values, $errors] = ClientService::parseTheme(['primary' => ' #1A2B5C ', 'accent' => 'rojo']);
        $this->assertSame(['primary' => '#1a2b5c', 'accent' => 'rojo'], $values);
        $this->assertSame(['accent' => 'Color de acento: use un color en formato #RRGGBB.'], $errors);
        $this->assertSame(
            ['primary' => 'Color principal: use un color en formato #RRGGBB.'],
            ClientService::parseTheme(['primary' => '#fff', 'accent' => '#ffffff'])[1],
        );
    }
}
