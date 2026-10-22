<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reversible encryption (AES-256-GCM) for each assessment's access password, so the admin
 * can see it again. The key:
 * - comes from ACCESS_PASSWORD_KEY (64 hex chars = 32 bytes) when set;
 * - otherwise it is created once in clave-cifrado.key: next to the SQLite file, or in
 *   database/ on MySQL/MariaDB.
 * Without the key nothing can be decrypted: include it in backups (or set it in .env).
 *
 * Payload format (same as the original app): "v1:<iv b64>:<tag b64>:<ciphertext b64>".
 */
final class SecretBox
{
    public const KEY_FILE = 'clave-cifrado.key';

    private static ?string $key = null;

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('No se pudo cifrar el dato.');
        }

        return implode(':', ['v1', base64_encode($iv), base64_encode($tag), base64_encode($ct)]);
    }

    /** Returns the text, or null when the payload is damaged or was encrypted with another key. */
    public static function decrypt(string $payload): ?string
    {
        $parts = explode(':', $payload);
        if (count($parts) !== 4 || $parts[0] !== 'v1' || in_array('', $parts, true)) {
            return null;
        }
        [, $iv, $tag, $ct] = $parts;
        $plain = openssl_decrypt(
            base64_decode($ct), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA,
            base64_decode($iv), base64_decode($tag),
        );

        return $plain === false ? null : $plain;
    }

    /** Forgets the cached key (tests and key rotation). */
    public static function reset(): void
    {
        self::$key = null;
    }

    private static function key(): string
    {
        return self::$key ??= self::loadKey();
    }

    private static function loadKey(): string
    {
        $envKey = config('assessments.access_password_key');
        if ($envKey) {
            if (! preg_match('/^[0-9a-f]{64}$/i', $envKey)) {
                throw new RuntimeException('ACCESS_PASSWORD_KEY debe tener 64 caracteres hexadecimales (32 bytes).');
            }

            return hex2bin($envKey);
        }

        // SQLite: next to the database file (an in-memory test database gets a throwaway key).
        // MySQL/MariaDB: always in database/, whatever folder PHP runs from (web or cron).
        if (DB::connection()->getDriverName() === 'sqlite') {
            $database = (string) DB::connection()->getConfig('database');
            if ($database === '' || $database === ':memory:') {
                return random_bytes(32);
            }
            $file = dirname($database).DIRECTORY_SEPARATOR.self::KEY_FILE;
        } else {
            $file = database_path(self::KEY_FILE);
        }
        if (is_file($file)) {
            $key = hex2bin(trim((string) file_get_contents($file)));
            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException("La clave de {$file} no es válida.");
            }

            return $key;
        }

        $key = random_bytes(32);
        file_put_contents($file, bin2hex($key)."\n");
        @chmod($file, 0600);
        logger()->info("[cifrado] Se creó la clave de cifrado en {$file}. Inclúyala en los respaldos.");

        return $key;
    }
}
