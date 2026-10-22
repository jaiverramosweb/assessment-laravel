<?php

namespace App\Services\Ai\Analysis;

use Normalizer;

/**
 * Anonymizes the answers before they are sent to the AI (port of src/lib/server/ai/anonymize.ts).
 * - Name, email, area and position of whoever answered are never sent: only the text.
 * - Inside the text, emails, phones and the names of the assessment's participants are replaced
 *   (in case someone names themselves or a colleague).
 * It is a minimization measure: third-party names that are not in the list may remain.
 */
final class Anonymizer
{
    // Bounded quantifiers: unbounded `+` makes a long text without "@" quadratic.
    private const EMAIL_RE = '/[A-Z0-9._%+-]{1,64}@[A-Z0-9.-]{1,253}\.[A-Z]{2,24}/i';

    // 7 or more digits, with optional spaces, dots, dashes or parentheses (e.g. +57 300 123 4567).
    private const PHONE_RE = '/\+?\d[\d\s().-]{5,20}\d/';

    // Common words never treated as part of a name. In open mode the participant types the name:
    // without this list, a "name" such as "que para con" would erase those words everywhere.
    private const STOPWORDS = 'que para con por los las del una uno unos unas como pero mas muy sin sobre entre '
        .'este esta esto ese esa eso son ser fue hay todo toda todos todas cuando donde '
        .'porque tambien desde hasta nos les sus mis tus equipo area empresa trabajo';

    private ?string $nameRe = null;

    /** @param  string[]  $names  known names (participants of the assessment) */
    public function __construct(array $names)
    {
        $tokens = [];
        foreach ($names as $name) {
            foreach (self::nameTokens($name) as $t) {
                $tokens[mb_strtolower(self::strip($t))] = true;
            }
        }
        $tokens = array_keys($tokens);
        // Longest first, so "Ana María Pérez" is replaced before "Ana".
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        if ($tokens) {
            $alt = implode('|', array_map(fn ($t) => preg_quote($t, '/'), $tokens));
            $this->nameRe = '/(?<![\p{L}\p{N}])(?:'.$alt.')(?![\p{L}\p{N}])/iu';
        }
    }

    public function apply(string $text): string
    {
        $out = preg_replace(self::EMAIL_RE, '[email]', $text) ?? $text;
        $out = preg_replace(self::PHONE_RE, '[teléfono]', $out) ?? $out;
        if ($this->nameRe === null) {
            return $out;
        }

        // The search runs on an accent-free copy and replaces in the original. The copy is built
        // character by character, keeping a map from its byte offsets to the original ones.
        $chars = mb_str_split($out);
        $plain = '';
        $starts = []; // plain byte offset => original byte offset (start of a non-empty piece)
        $origOffset = 0;
        foreach ($chars as $ch) {
            $piece = self::strip($ch);
            if ($piece !== '' && ! isset($starts[strlen($plain)])) {
                $starts[strlen($plain)] = $origOffset;
            }
            $plain .= $piece;
            $origOffset += strlen($ch);
        }
        $starts[strlen($plain)] = $origOffset;

        if (! preg_match_all($this->nameRe, $plain, $matches, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        $result = '';
        $last = 0;
        foreach ($matches[0] as [$match, $offset]) {
            $from = self::origAt($starts, $offset);
            $to = self::origAt($starts, $offset + strlen($match));
            $result .= substr($out, $last, $from - $last).'[persona]';
            $last = $to;
        }

        return $result.substr($out, $last);
    }

    /**
     * Splits the answers into blocks of bounded size. An answer is never split between blocks.
     *
     * @param  string[]  $responses
     * @return array<int, string[]>
     */
    public static function chunk(array $responses, int $maxChars): array
    {
        $chunks = [];
        $current = [];
        $size = 0;
        foreach ($responses as $r) {
            $len = mb_strlen($r);
            if ($current && $size + $len > $maxChars) {
                $chunks[] = $current;
                $current = [];
                $size = 0;
            }
            $current[] = $r;
            $size += $len;
        }
        if ($current) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** Original offset for a plain offset (a match boundary falls on a piece start, or rounds up). */
    private static function origAt(array $starts, int $plainOffset): int
    {
        while (! isset($starts[$plainOffset])) {
            $plainOffset++;
        }

        return $starts[$plainOffset];
    }

    private static function strip(string $s): string
    {
        $nfd = Normalizer::normalize($s, Normalizer::FORM_D);

        return preg_replace('/[\x{0300}-\x{036f}]/u', '', $nfd === false ? $s : $nfd) ?? $s;
    }

    private static function isStopword(string $word): bool
    {
        static $set = null;
        $set ??= array_flip(explode(' ', self::STOPWORDS));

        return isset($set[mb_strtolower(self::strip($word))]);
    }

    /** Words of a name worth searching (short and common ones are skipped). */
    private static function nameTokens(string $fullName): array
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', $fullName) ?: [],
            fn ($w) => mb_strlen($w) >= 3 && ! self::isStopword($w)
        ));
        $full = trim($fullName);
        // The full name is only used when it is not, itself, a single common word.
        $useFull = $full !== '' && ! self::isStopword($full);

        return [...($useFull ? [$full] : []), ...$words];
    }
}
