<?php

namespace App\Auth;

/**
 * Validates the login `destino` parameter to prevent open redirects: only internal panel
 * paths (/admin...) are accepted. Anything else (absolute URLs, "//other-site",
 * "/\other-site") goes back to the panel home.
 */
final class SafeRedirect
{
    public static function admin(?string $destino): string
    {
        $fallback = '/admin';
        if (! $destino || ! str_starts_with($destino, '/admin')) {
            return $fallback;
        }
        if (str_starts_with($destino, '//') || str_contains($destino, '\\')) {
            return $fallback;
        }
        if (str_starts_with($destino, '/admin/login') || str_starts_with($destino, '/admin/logout')) {
            return $fallback;
        }
        // Must be exactly /admin or continue with /, ? or # (not "/administrador-falso").
        $next = substr($destino, strlen('/admin'), 1);
        if ($next !== '' && ! in_array($next, ['/', '?', '#'], true)) {
            return $fallback;
        }

        return $destino;
    }
}
