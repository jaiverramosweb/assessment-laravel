<?php

namespace App\Services\Reports;

use App\Services\Invitations\Invitations;
use Collator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Text formats of the reports and exports (PHP port of resources/js/lib/format.ts), so the
 * server-rendered PDF/Excel read the same as the screens.
 */
final class Format
{
    public const DEFAULT_TIMEZONE = 'America/Bogota';

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

    private static ?Collator $collator = null;

    /** "67%" / "61,1%" (es-CO); "—" when there is no value. */
    public static function percent(int|float|null $rate, int $digits = 0): string
    {
        if ($rate === null) {
            return '—';
        }

        return number_format(round($rate * 100, $digits), $digits, ',', '.').'%';
    }

    /** Difference between two proportions in percentage points: "+12 pp", "−5 pp", "0 pp" or "—". */
    public static function points(int|float|null $delta): string
    {
        if ($delta === null) {
            return '—';
        }
        $pp = (int) round($delta * 100);
        if ($pp === 0) {
            return '0 pp';
        }

        return ($pp > 0 ? '+' : '−').abs($pp).' pp';
    }

    /** "1 de noviembre de 2026 a las 8:00 a. m." (same as the original `formatLongDateTime`). */
    public static function longDateTime(int $ms, string $timeZone = self::DEFAULT_TIMEZONE): string
    {
        return Invitations::formatLongDateTime($ms, $timeZone);
    }

    /** Short date: "18 oct 2026". */
    public static function shortDate(int $ms, string $timeZone = self::DEFAULT_TIMEZONE): string
    {
        $d = self::local($ms, $timeZone);

        return $d->format('j').' '.self::MONTHS[(int) $d->format('n') - 1].' '.$d->format('Y');
    }

    /** Wall clock of an instant (ms) in a time zone. */
    public static function local(int $ms, string $timeZone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.intdiv($ms, 1000)))->setTimezone(new DateTimeZone($timeZone));
    }

    /** Spanish string comparison (like `localeCompare(…, 'es')`). */
    public static function compare(string $a, string $b): int
    {
        self::$collator ??= new Collator('es');

        return self::$collator->compare($a, $b);
    }
}
