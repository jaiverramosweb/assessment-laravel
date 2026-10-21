<?php

namespace App\Auth;

final class Tokens
{
    /** Random 256-bit token for session cookies (base64url). */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * What is stored in the database is the SHA-256 of the token, not the token:
     * whoever reads the database cannot use active sessions.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
