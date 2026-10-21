<?php

namespace App\Services\ParticipantFlow;

use IntlDateFormatter;

/** Dates in the participant's messages, formatted like the original `formatLongDateTime`. */
final class Dates
{
    /** "1 de noviembre de 2026 a las 8:00 a. m." (non-breaking spaces so "a. m." is never split). */
    public static function long(int $ms, string $timezone): string
    {
        $formatter = new IntlDateFormatter(
            'es_CO',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone,
            IntlDateFormatter::GREGORIAN,
            "d 'de' MMMM 'de' y 'a las' h:mm a",
        );
        $text = (string) $formatter->format(intdiv($ms, 1000));

        // ICU versions differ in the spaces around "a. m.": normalize to the original output.
        return (string) preg_replace('/[ \x{00A0}\x{202F}]([ap])\.[\s\x{00A0}\x{202F}]?m\./u', "\u{00A0}$1.\u{00A0}m.", $text);
    }
}
