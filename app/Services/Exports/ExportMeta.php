<?php

namespace App\Services\Exports;

use App\Models\Assessment;
use App\Services\Clients\ClientService;
use App\Services\Reports\Format;
use App\Services\Shared\Brand;
use App\Support\Clock;
use App\Support\Theme;
use Normalizer;

/** Header data shared by the Excel and PDF exports (port of src/lib/server/export/common.ts). */
final class ExportMeta
{
    public const STATUS_TEXT = [
        'pendiente' => 'Pendiente',
        'en_progreso' => 'En progreso',
        'completado' => 'Completado',
    ];

    public const ASSESSMENT_STATUS_TEXT = [
        'borrador' => 'Borrador',
        'publicado' => 'Publicado',
        'archivado' => 'Archivado',
    ];

    /**
     * @param  array{area?: ?string, cargo?: ?string}  $filters
     * @return array{title: string, description: string, timezone: string, period: string, generatedAt: string,
     *     filtersLabel: string, logoDataUrl: ?string, themePrimary: ?string, themeAccent: ?string}
     */
    public static function forAssessment(Assessment $a, array $filters, ?int $nowMs = null): array
    {
        $parts = array_values(array_filter([
            ! empty($filters['area']) ? "Área: {$filters['area']}" : null,
            ! empty($filters['cargo']) ? "Cargo: {$filters['cargo']}" : null,
        ]));

        return [
            'title' => $a->title,
            'description' => (string) $a->description,
            'timezone' => $a->timezone,
            'period' => 'Del '.Format::longDateTime($a->starts_at, $a->timezone).' al '.Format::longDateTime($a->ends_at, $a->timezone),
            'generatedAt' => Format::longDateTime($nowMs ?? Clock::nowMs(), $a->timezone),
            'filtersLabel' => $parts ? implode(' · ', $parts) : 'Todos los participantes',
            ...self::brand($a->client_id),
        ];
    }

    /**
     * Logo (data URL, only PNG/JPEG: the formats the PDF accepts) and colors (#rrggbb) of a client.
     *
     * @return array{logoDataUrl: ?string, themePrimary: ?string, themeAccent: ?string}
     */
    public static function brand(?string $clientId): array
    {
        $brand = Brand::get($clientId);
        $logo = ClientService::readLogo($clientId);

        return [
            'logoDataUrl' => $logo && in_array($logo['mime'], ['image/png', 'image/jpeg'], true)
                ? "data:{$logo['mime']};base64,".base64_encode($logo['data'])
                : null,
            'themePrimary' => Theme::isHexColor($brand['theme_primary'] ?? null) ? $brand['theme_primary'] : null,
            'themeAccent' => Theme::isHexColor($brand['theme_accent'] ?? null) ? $brand['theme_accent'] : null,
        ];
    }

    /** Safe file name: "informe-seguridad-de-la-informacion-2026-11-05.xlsx". */
    public static function fileName(string $title, string $ext, ?int $nowMs = null): string
    {
        $text = Normalizer::normalize($title, Normalizer::FORM_D) ?: $title;
        $text = preg_replace('/[\x{0300}-\x{036f}]/u', '', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($text));
        $slug = substr(preg_replace('/^-|-$/', '', $text), 0, 60) ?: 'assessment';
        $date = gmdate('Y-m-d', intdiv($nowMs ?? Clock::nowMs(), 1000));

        return "informe-{$slug}-{$date}.{$ext}";
    }
}
