<?php

namespace App\Auth;

/**
 * Argon2id with OWASP's minimum recommended parameters (19 MiB memory, 2 iterations, 1 thread).
 * Hashes use the PHC format, the same one the original app stored.
 */
final class Passwords
{
    private const OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

    private static ?string $dummyHash = null;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    /** Returns false (never throws) when the hash is empty or malformed. */
    public static function verify(?string $hash, string $password): bool
    {
        if (! $hash) {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Hash of a random password. It is verified when the email does not exist,
     * so the response takes the same time and does not reveal which emails exist.
     */
    public static function dummyHash(): string
    {
        return self::$dummyHash ??= self::hash(bin2hex(random_bytes(16)));
    }
}
