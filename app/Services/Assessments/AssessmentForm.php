<?php

namespace App\Services\Assessments;

use App\Models\Assessment;
use App\Support\Clock;
use Illuminate\Http\Request;

/**
 * Assessment general data form (port of src/lib/schemas/assessment.ts and src/lib/server/forms.ts).
 * Dates arrive as <input type="datetime-local"> text and are converted to UTC ms with the
 * chosen timezone. Messages are the original ones, verbatim.
 */
final class AssessmentForm
{
    public const DEFAULT_TIMEZONE = 'America/Bogota';

    public const FIELDS = [
        'title', 'clientId', 'description', 'instructions', 'timezone',
        'startsAt', 'endsAt', 'accessMode', 'openMaxChars',
    ];

    /** Reads the form fields as text (to validate and show again). */
    public static function read(Request $request): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $v = $request->input($field);
            $values[$field] = is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '');
        }

        return $values;
    }

    public static function fromAssessment(Assessment $a): array
    {
        return [
            'title' => $a->title,
            'clientId' => $a->client_id ?? '',
            'description' => $a->description,
            'instructions' => $a->instructions,
            'timezone' => $a->timezone,
            'startsAt' => LocalDateTime::format($a->starts_at, $a->timezone),
            'endsAt' => LocalDateTime::format($a->ends_at, $a->timezone),
            'accessMode' => $a->access_mode,
            'openMaxChars' => $a->open_max_chars,
        ];
    }

    /** Suggested values for a new assessment: starts tomorrow at 8:00 and lasts one week. */
    public static function defaults(string $clientId = ''): array
    {
        $day = 24 * 3600 * 1000;
        $tomorrow = Clock::nowMs() + $day;
        $start = substr(LocalDateTime::format($tomorrow, self::DEFAULT_TIMEZONE), 0, 10).'T08:00';
        $end = substr(LocalDateTime::format($tomorrow + 7 * $day, self::DEFAULT_TIMEZONE), 0, 10).'T18:00';

        return [
            'title' => '',
            'clientId' => $clientId,
            'description' => '',
            'instructions' => '',
            'timezone' => self::DEFAULT_TIMEZONE,
            'startsAt' => $start,
            'endsAt' => $end,
            'accessMode' => 'invitados',
            'openMaxChars' => 8000,
        ];
    }

    /**
     * Validates the raw values. Returns ['data' => columns] or ['errors' => field => first message].
     * Like the original, the date rules only run once every field is valid.
     *
     * @return array{data?: array, errors?: array<string, string>}
     */
    public static function validate(array $values): array
    {
        $s = fn (string $k) => is_string($values[$k] ?? null) ? $values[$k] : (string) ($values[$k] ?? '');
        $errors = [];

        $title = self::trim($s('title'));
        if (mb_strlen($title) > 200) {
            $errors['title'] = 'Título: máximo 200 caracteres.';
        } elseif (mb_strlen($title) < 3) {
            $errors['title'] = 'El título debe tener al menos 3 caracteres.';
        }

        $clientId = self::trim($s('clientId'));

        $description = self::trim($s('description'));
        if (mb_strlen($description) > 5000) {
            $errors['description'] = 'Descripción: máximo 5.000 caracteres.';
        }
        $instructions = self::trim($s('instructions'));
        if (mb_strlen($instructions) > 10000) {
            $errors['instructions'] = 'Instrucciones: máximo 10.000 caracteres.';
        }

        $timezone = self::trim($s('timezone'));
        if (! LocalDateTime::isValidTimeZone($timezone)) {
            $errors['timezone'] = 'Zona horaria no válida.';
        }

        if ($s('startsAt') === '') {
            $errors['startsAt'] = 'Indique la fecha y hora de inicio.';
        }
        if ($s('endsAt') === '') {
            $errors['endsAt'] = 'Indique la fecha y hora de finalización.';
        }

        $accessMode = $s('accessMode');
        if (! in_array($accessMode, Assessment::ACCESS_MODES, true)) {
            $errors['accessMode'] = 'Modo de acceso no válido.';
        }

        $maxChars = self::number($s('openMaxChars'));
        if ($maxChars === null) {
            $errors['openMaxChars'] = 'El límite de caracteres debe ser un número.';
        } elseif (floor($maxChars) !== $maxChars) {
            $errors['openMaxChars'] = 'El límite de caracteres debe ser un número entero.';
        } elseif ($maxChars < 500) {
            $errors['openMaxChars'] = 'El límite de caracteres debe ser al menos 500.';
        } elseif ($maxChars > 50000) {
            $errors['openMaxChars'] = 'El límite de caracteres no puede superar 50.000.';
        }

        if ($errors) {
            return ['errors' => $errors];
        }

        $startsAt = LocalDateTime::parse($s('startsAt'), $timezone);
        $endsAt = LocalDateTime::parse($s('endsAt'), $timezone);
        if ($startsAt === null) {
            $errors['startsAt'] = 'Fecha de inicio no válida.';
        }
        if ($endsAt === null) {
            $errors['endsAt'] = 'Fecha de finalización no válida.';
        }
        if ($errors) {
            return ['errors' => $errors];
        }
        if ($endsAt <= $startsAt) {
            return ['errors' => ['endsAt' => 'La finalización debe ser posterior al inicio.']];
        }

        return ['data' => [
            'title' => $title,
            'client_id' => $clientId !== '' ? $clientId : null,
            'description' => $description,
            'instructions' => $instructions,
            'timezone' => $timezone,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'access_mode' => $accessMode,
            'open_max_chars' => (int) $maxChars,
        ]];
    }

    /**
     * Shared access password. Returns field => first message (empty when valid).
     *
     * @return array<string, string>
     */
    public static function validateAccessPassword(mixed $password, mixed $confirm): array
    {
        $password = is_string($password) ? $password : '';
        $confirm = is_string($confirm) ? $confirm : '';
        $errors = [];
        if (mb_strlen($password) < 12) {
            $errors['password'] = 'La contraseña de acceso debe tener al menos 12 caracteres.';
        } elseif (mb_strlen($password) > 100) {
            $errors['password'] = 'La contraseña de acceso es demasiado larga.';
        }
        if ($password !== $confirm) {
            $errors['confirm'] = 'Las contraseñas no coinciden.';
        }

        return $errors;
    }

    /** JavaScript-like trim (also removes Unicode spaces). */
    private static function trim(string $v): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $v) ?? trim($v);
    }

    /** Number(value) of JavaScript: '' → 0, invalid → null (NaN). */
    private static function number(string $v): ?float
    {
        $t = self::trim($v);
        if ($t === '') {
            return 0.0;
        }

        return is_numeric($t) ? (float) $t : null;
    }
}
