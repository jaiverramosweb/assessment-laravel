<?php

namespace App\Services\Assessments;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Conversion between an <input type="datetime-local"> value ("2026-10-20T08:30"), which has no
 * timezone, and a UTC instant in ms, using the assessment timezone (port of src/lib/datetime.ts).
 */
final class LocalDateTime
{
    /** Timezones suggested first in the selector. */
    public const COMMON_TIMEZONES = [
        'America/Bogota',
        'America/Mexico_City',
        'America/Lima',
        'America/Guayaquil',
        'America/Panama',
        'America/Caracas',
        'America/Santiago',
        'America/Argentina/Buenos_Aires',
        'America/Sao_Paulo',
        'America/New_York',
        'Europe/Madrid',
        'UTC',
    ];

    private const LOCAL_RE = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/';

    private static ?array $all = null;

    /** Reads "YYYY-MM-DDTHH:mm" as local time of `$timeZone`. Returns null when invalid. */
    public static function parse(string $value, string $timeZone): ?int
    {
        if (! preg_match(self::LOCAL_RE, trim($value), $m) || ! self::isValidTimeZone($timeZone)) {
            return null;
        }
        [$y, $mo, $d, $h, $mi] = array_map('intval', array_slice($m, 1));
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $h > 23 || $mi > 59) {
            return null;
        }

        $date = (new DateTimeImmutable('now', new DateTimeZone($timeZone)))
            ->setDate($y, $mo, $d)
            ->setTime($h, $mi, 0, 0);
        // Rejects dates that get "corrected" (e.g. February 31 → March 3).
        if ((int) $date->format('Y') !== $y || (int) $date->format('n') !== $mo || (int) $date->format('j') !== $d) {
            return null;
        }

        return $date->getTimestamp() * 1000;
    }

    /** Formats an instant (ms) as "YYYY-MM-DDTHH:mm" in the given timezone (for the input). */
    public static function format(int $ms, string $timeZone): string
    {
        $seconds = intdiv($ms, 1000) - ($ms % 1000 < 0 ? 1 : 0);

        return (new DateTimeImmutable('@'.$seconds))
            ->setTimezone(new DateTimeZone(self::isValidTimeZone($timeZone) ? $timeZone : 'UTC'))
            ->format('Y-m-d\TH:i');
    }

    public static function isValidTimeZone(string $timeZone): bool
    {
        if ($timeZone === '' || ! in_array($timeZone, self::identifiers(), true)) {
            return false;
        }
        try {
            new DateTimeZone($timeZone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return string[] common zones first, then the rest of the IANA list. */
    public static function allTimeZones(): array
    {
        $rest = array_values(array_diff(DateTimeZone::listIdentifiers(), self::COMMON_TIMEZONES));

        return [...self::COMMON_TIMEZONES, ...$rest];
    }

    private static function identifiers(): array
    {
        return self::$all ??= [...DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), 'UTC'];
    }
}
