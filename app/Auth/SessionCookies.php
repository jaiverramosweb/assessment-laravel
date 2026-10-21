<?php

namespace App\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/** Common options for the admin and participant session cookies. */
final class SessionCookies
{
    public static function make(string $name, string $value, int $expiresAtMs, string $path = '/'): Cookie
    {
        return Cookie::create(
            $name,
            $value,
            intdiv($expiresAtMs, 1000),
            $path,
            null,
            (bool) config('assessments.secure_cookies'),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    public static function forget(string $name, string $path = '/'): Cookie
    {
        return Cookie::create(
            $name,
            '',
            1,
            $path,
            null,
            (bool) config('assessments.secure_cookies'),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
