<?php

namespace App\Services\Participants;

/**
 * Validation and normalization of one participant (port of the original `participantSchema`).
 * Fields are checked in schema order: name, email, area, cargo, extra.
 */
final class ParticipantInput
{
    /** Same pattern zod uses for `z.email()`. */
    private const EMAIL_PATTERN = "/^(?!\\.)(?!.*\\.\\.)([A-Za-z0-9_'+\\-\\.]*)[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9\\-]*\\.)+[A-Za-z]{2,}$/";

    /**
     * @param  array{name?: mixed, email?: mixed, area?: mixed, cargo?: mixed, extra?: array<string, string>|null}  $values
     * @return array{ok: true, data: array{name: string, email: string, area: ?string, cargo: ?string, extra: ?array}}|array{ok: false, errors: array<string, string>}
     */
    public static function validate(array $values): array
    {
        $errors = [];

        $name = $values['name'] ?? null;
        if (! is_string($name)) {
            $errors['name'] = 'Escriba el nombre.';
        } else {
            $name = trim($name);
            if (mb_strlen($name) < 2) {
                $errors['name'] = 'El nombre debe tener al menos 2 caracteres.';
            } elseif (mb_strlen($name) > 150) {
                $errors['name'] = 'El nombre no puede superar 150 caracteres.';
            }
        }

        $email = self::normalizeEmail($values['email'] ?? null);
        if ($email === null) {
            $errors['email'] = 'Escriba un email.';
        } elseif (mb_strlen($email) > 254) {
            $errors['email'] = 'El email es demasiado largo.';
        } elseif (! preg_match(self::EMAIL_PATTERN, $email)) {
            $errors['email'] = 'Escriba un email válido.';
        }

        $area = self::optionalText($values['area'] ?? null, 100, 'Área', 'area', $errors);
        $cargo = self::optionalText($values['cargo'] ?? null, 100, 'Cargo', 'cargo', $errors);

        $extra = $values['extra'] ?? null;
        foreach ($extra ?? [] as $value) {
            if (mb_strlen((string) $value) > 500) {
                $errors['extra'] ??= 'Datos adicionales: máximo 500 caracteres.';
            }
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'data' => [
            'name' => $name, 'email' => $email, 'area' => $area, 'cargo' => $cargo, 'extra' => $extra ?: null,
        ]];
    }

    /** Emails are always stored trimmed and lowercase. */
    public static function normalizeEmail(mixed $email): ?string
    {
        return is_string($email) ? mb_strtolower(trim($email)) : null;
    }

    private static function optionalText(mixed $value, int $max, string $label, string $key, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            $errors[$key] = "{$label}: máximo {$max} caracteres.";
        }

        return $value === '' ? null : $value;
    }
}
