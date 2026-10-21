<?php

namespace App\Services\Shared;

use App\Models\Client;
use App\Support\Theme;

/** Client brand (logo and colors) that its assessments inherit. */
final class Brand
{
    /**
     * Brand of a client without the image. Null = platform colors and no logo.
     *
     * @return array{id: string, name: string, logo_mime: ?string, logo_updated_at: ?int, theme_primary: ?string, theme_accent: ?string}|null
     */
    public static function get(?string $clientId): ?array
    {
        if (! $clientId) {
            return null;
        }
        $client = Client::query()
            ->select(['id', 'name', 'logo_mime', 'logo_updated_at', 'theme_primary', 'theme_accent'])
            ->find($clientId);

        return $client?->only(['id', 'name', 'logo_mime', 'logo_updated_at', 'theme_primary', 'theme_accent']);
    }

    /**
     * Public URL of the logo the participant sees, or null. It carries the update time as a
     * version so the browser can cache it.
     */
    public static function logoUrl(string $slug, ?array $brand): ?string
    {
        if (empty($brand['logo_mime']) || empty($brand['logo_updated_at'])) {
            return null;
        }

        return "/a/{$slug}/logo?v={$brand['logo_updated_at']}";
    }

    /** Logo URL in the admin panel (works for clients without assessments too). */
    public static function clientLogoUrl(Client|array $client): ?string
    {
        $c = $client instanceof Client ? $client->only(['id', 'logo_mime', 'logo_updated_at']) : $client;
        if (empty($c['logo_mime']) || empty($c['logo_updated_at'])) {
            return null;
        }

        return "/admin/clientes/{$c['id']}/logo?v={$c['logo_updated_at']}";
    }

    /** @return array{logoUrl: ?string, themeStyle: ?string} logo and colors for participant screens. */
    public static function forParticipant(string $slug, ?string $clientId): array
    {
        $brand = self::get($clientId);

        return [
            'logoUrl' => self::logoUrl($slug, $brand),
            'themeStyle' => Theme::style($brand['theme_primary'] ?? null, $brand['theme_accent'] ?? null),
        ];
    }
}
