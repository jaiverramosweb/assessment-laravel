<?php

namespace App\Services\ParticipantFlow;

use Illuminate\Support\Facades\Validator;

/**
 * Participant entry form (port of the original schemas/access.ts `enterSchema`).
 * Fields are checked in the original order (password, email, name); the first error wins.
 */
final class EnterForm
{
    /** @return array{ok: true, password: string, email: string, name: ?string}|array{ok: false, error: string} */
    public static function validate(array $input): array
    {
        $password = $input['password'] ?? null;
        if (! is_string($password) || $password === '') {
            return self::error('Escriba la contraseña de acceso.');
        }
        if (mb_strlen($password) > 100) {
            return self::error('La contraseña es demasiado larga.');
        }

        $email = $input['email'] ?? null;
        if (! is_string($email)) {
            return self::error('Escriba un email.');
        }
        $email = mb_strtolower(trim($email));
        if (mb_strlen($email) > 254) {
            return self::error('El email es demasiado largo.');
        }
        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            return self::error('Escriba un email válido.');
        }

        $name = $input['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';
        if (mb_strlen($name) > 150) {
            return self::error('El nombre no puede superar 150 caracteres.');
        }
        // The name is included in emails: no links or line breaks.
        if (preg_match('/[\r\n]/', $name)) {
            return self::error('El nombre no puede tener saltos de línea.');
        }
        if (preg_match('/https?:\/\/|www\./i', $name)) {
            return self::error('El nombre no puede contener enlaces.');
        }
        if ($name !== '' && mb_strlen($name) < 2) {
            return self::error('El nombre debe tener al menos 2 caracteres.');
        }

        return ['ok' => true, 'password' => $password, 'email' => $email, 'name' => $name === '' ? null : $name];
    }

    private static function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
