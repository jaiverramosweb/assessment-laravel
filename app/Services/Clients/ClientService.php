<?php

namespace App\Services\Clients;

use App\Models\Assessment;
use App\Models\Client;
use App\Security\Audit;
use App\Services\Shared\Brand;
use App\Support\Clock;
use App\Support\Theme;
use Illuminate\Support\Facades\DB;

/**
 * Clients group assessments and give them their brand (logo and colors).
 * The brand lives only on the client: all its assessments inherit it.
 */
final class ClientService
{
    public const LOGO_MAX_BYTES = 2 * 1024 * 1024; // high-resolution logos

    public const DELETE_IN_USE = 'El cliente tiene assessments. Elimínelos o asígnelos a otro cliente primero.';

    public const NAME_TAKEN = 'Ya existe un cliente con ese nombre.';

    private const NAME_MAX = 120;

    // ---- Validation (port of clientSchema / themeSchema) ----------------------------

    /**
     * Trims and validates a client name.
     *
     * @return array{0: string, 1: ?string} [value, first error or null]
     */
    public static function parseName(mixed $input): array
    {
        $name = trim(is_string($input) ? $input : '');
        $error = match (true) {
            mb_strlen($name) > self::NAME_MAX => 'Nombre: máximo '.self::NAME_MAX.' caracteres.',
            mb_strlen($name) < 2 => 'El nombre debe tener al menos 2 caracteres.',
            (bool) preg_match('/[\r\n]/', $name) => 'El nombre no puede tener saltos de línea.',
            default => null,
        };

        return [$name, $error];
    }

    /**
     * Normalizes (trim + lowercase) and validates the client colors. Light tones are allowed:
     * the screen warns about readability and the admin decides.
     *
     * @return array{0: array{primary: string, accent: string}, 1: array<string, string>}
     */
    public static function parseTheme(array $input): array
    {
        $labels = ['primary' => 'Color principal', 'accent' => 'Color de acento'];
        $values = [];
        $errors = [];
        foreach ($labels as $key => $label) {
            $values[$key] = mb_strtolower(trim(is_string($input[$key] ?? null) ? $input[$key] : ''));
            if (! Theme::isHexColor($values[$key])) {
                $errors[$key] = "{$label}: use un color en formato #RRGGBB.";
            }
        }

        return [$values, $errors];
    }

    // ---- Queries -----------------------------------------------------------------------

    /**
     * Clients ordered by name (case-insensitive) with their assessment count, without the image.
     *
     * @return list<array{id: string, name: string, logoUrl: ?string, themePrimary: ?string, themeAccent: ?string, assessmentCount: int}>
     */
    public static function list(): array
    {
        return Client::query()
            ->select(['id', 'name', 'logo_mime', 'logo_updated_at', 'theme_primary', 'theme_accent'])
            ->withCount('assessments')
            ->orderByRaw('lower(name)')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'logoUrl' => Brand::clientLogoUrl($c),
                'themePrimary' => $c->theme_primary,
                'themeAccent' => $c->theme_accent,
                'assessmentCount' => (int) $c->assessments_count,
            ])
            ->all();
    }

    /** Loads the client from the URL or responds 404 (ids must look like a UUID). */
    public static function require(string $id): Client
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            abort(404, 'No encontrado');
        }

        return Client::query()
            ->select(['id', 'name', 'logo_mime', 'logo_updated_at', 'theme_primary', 'theme_accent', 'created_at', 'updated_at'])
            ->find($id) ?? abort(404, 'Cliente no encontrado');
    }

    /** Is there another client with that name? (case-insensitive) */
    public static function nameTaken(string $name, ?string $exceptId = null): bool
    {
        $id = Client::query()->whereRaw('lower(name) = lower(?)', [$name])->value('id');

        return $id !== null && $id !== $exceptId;
    }

    /**
     * Assessments of a client (archived included), newest first, with participation counts.
     *
     * @return list<array{id: string, title: string, status: string, startsAt: int, endsAt: int, timezone: string, participantCount: int, submittedCount: int}>
     */
    public static function assessments(string $clientId): array
    {
        return Assessment::query()
            ->where('client_id', $clientId)
            ->select(['id', 'title', 'status', 'starts_at', 'ends_at', 'timezone'])
            ->selectRaw('(select count(*) from participants p where p.assessment_id = assessments.id) as participant_count')
            ->selectRaw('(select count(*) from submissions s where s.assessment_id = assessments.id and s.submitted_at is not null) as submitted_count')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Assessment $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'status' => $a->status,
                'startsAt' => $a->starts_at,
                'endsAt' => $a->ends_at,
                'timezone' => $a->timezone,
                'participantCount' => (int) $a->participant_count,
                'submittedCount' => (int) $a->submitted_count,
            ])
            ->all();
    }

    // ---- Changes -----------------------------------------------------------------------

    public static function create(string $name, string $adminId, ?string $ip = null): Client
    {
        $client = Client::create(['name' => $name, 'created_by_id' => $adminId]);
        Audit::log($adminId, 'cliente.creado', 'cliente', $client->id, ['nombre' => $name], $ip);

        return $client;
    }

    public static function rename(string $id, string $name, string $adminId, ?string $ip = null): void
    {
        self::touch($id, ['name' => $name], $adminId, ['nombre' => $name], $ip);
    }

    /** Saves the colors (already validated with parseTheme) or goes back to the platform ones. */
    public static function setTheme(string $id, ?array $theme, string $adminId, ?string $ip = null): void
    {
        self::touch(
            $id,
            ['theme_primary' => $theme['primary'] ?? null, 'theme_accent' => $theme['accent'] ?? null],
            $adminId,
            $theme
                ? ['cambio' => 'colores', 'principal' => $theme['primary'], 'acento' => $theme['accent']]
                : ['cambio' => 'colores de la plataforma'],
            $ip,
        );
    }

    /** Stores the logo (validated by its content) or removes it with null. */
    public static function setLogo(string $id, ?string $data, string $adminId, ?string $ip = null): void
    {
        $mime = $data !== null ? self::detectLogoType($data) : null;
        $blob = null;
        if ($data !== null) {
            // A stream is bound as PDO::PARAM_LOB, so SQLite stores a real BLOB (not TEXT).
            $blob = fopen('php://memory', 'r+b');
            fwrite($blob, $data);
            rewind($blob);
        }
        try {
            self::touch(
                $id,
                ['logo_data' => $blob, 'logo_mime' => $mime, 'logo_updated_at' => $data !== null ? Clock::nowMs() : null],
                $adminId,
                ['cambio' => $data !== null ? 'logo actualizado' : 'logo eliminado'],
                $ip,
            );
        } finally {
            if ($blob) {
                fclose($blob);
            }
        }
    }

    /** Only a client without assessments is deleted (so assessments never lose their brand by mistake). */
    public static function delete(Client $client, string $adminId, ?string $ip = null): void
    {
        DB::transaction(function () use ($client, $adminId, $ip) {
            if (Assessment::where('client_id', $client->id)->exists()) {
                throw new ClientInUseError(self::DELETE_IN_USE);
            }
            Client::whereKey($client->id)->delete();
            Audit::log($adminId, 'cliente.eliminado', 'cliente', $client->id, ['nombre' => $client->name], $ip);
        });
    }

    private static function touch(string $id, array $set, string $adminId, array $change, ?string $ip): void
    {
        // Query builder (not the model): the logo stream must reach PDO untouched.
        DB::table('clients')->where('id', $id)->update([...$set, 'updated_at' => Clock::nowMs()]);
        Audit::log($adminId, 'cliente.editado', 'cliente', $id, $change, $ip);
    }

    // ---- Logo ----------------------------------------------------------------------------

    /**
     * Validates the logo by its content (not by the extension or the type the browser declares).
     * SVG is not accepted: it can contain scripts.
     */
    public static function detectLogoType(string $data): string
    {
        if ($data === '') {
            throw new LogoError('Seleccione una imagen.');
        }
        if (strlen($data) > self::LOGO_MAX_BYTES) {
            throw new LogoError('El logo no puede superar 2 MB.');
        }

        return match (true) {
            str_starts_with($data, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($data, "\xFF\xD8\xFF") => 'image/jpeg',
            substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP' => 'image/webp',
            default => throw new LogoError('Formato no soportado. Use una imagen PNG, JPG o WebP.'),
        };
    }

    /**
     * Logo image of a client, or null when it has none.
     *
     * @return array{data: string, mime: string}|null
     */
    public static function readLogo(?string $clientId): ?array
    {
        if (! $clientId) {
            return null;
        }
        $row = DB::table('clients')->where('id', $clientId)->first(['logo_data', 'logo_mime']);
        $data = $row?->logo_data;
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        return is_string($data) && $data !== '' && $row->logo_mime ? ['data' => $data, 'mime' => $row->logo_mime] : null;
    }
}
