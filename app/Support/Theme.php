<?php

namespace App\Support;

/**
 * Client colors (PHP port of resources/js/lib/theme.ts, used by server-rendered output).
 * - primary replaces the platform dark color; accent replaces the brand blue.
 * White text goes on both, so dark tones are recommended (contrast >= 4.5:1).
 */
final class Theme
{
    public const DEFAULT_PRIMARY = '#1a1c23';

    public const DEFAULT_ACCENT = '#374ef7';

    public const MIN_CONTRAST = 4.5;

    public static function isHexColor(?string $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    /** WCAG contrast between two colors (1 to 21). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public static function readableWithWhite(string $hex): bool
    {
        return self::contrast($hex, '#ffffff') >= self::MIN_CONTRAST;
    }

    /** Mixes `color` with `other` (0 = color, 1 = other). */
    public static function mix(string $color, string $other, float $amount): string
    {
        $a = self::toRgb($color);
        $b = self::toRgb($other);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int) round($a[$i] + ($b[$i] - $a[$i]) * $amount)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * CSS variables for a container: the client colors replace Tailwind's `ink` and `brand`
     * scales inside it. Null when the platform colors are used.
     */
    public static function style(?string $primary, ?string $accent): ?string
    {
        $primary = self::isHexColor($primary) ? $primary : null;
        $accent = self::isHexColor($accent) ? $accent : null;
        if (! $primary && ! $accent) {
            return null;
        }

        $vars = [];
        if ($primary) {
            $vars['--color-ink-950'] = $primary;
            $vars['--color-ink-900'] = self::mix($primary, '#ffffff', 0.08);
            $vars['--color-ink-800'] = self::mix($primary, '#ffffff', 0.14);
            $vars['--color-ink-300'] = self::mix('#ffffff', $primary, 0.22);
        }
        if ($accent) {
            $vars['--color-brand-50'] = self::mix($accent, '#ffffff', 0.9);
            $vars['--color-brand-100'] = self::mix($accent, '#ffffff', 0.8);
            $vars['--color-brand-200'] = self::mix($accent, '#ffffff', 0.62);
            $vars['--color-brand-300'] = self::mix($accent, '#ffffff', 0.45);
            $vars['--color-brand-500'] = self::mix($accent, '#ffffff', 0.12);
            $vars['--color-brand-600'] = $accent;
            $vars['--color-brand-700'] = self::mix($accent, '#000000', 0.2);
            $vars['--color-brand-800'] = self::mix($accent, '#000000', 0.38);
        }

        return implode('; ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($vars), $vars));
    }

    private static function toRgb(string $hex): array
    {
        $n = hexdec(substr($hex, 1));

        return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(function ($v) {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::toRgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
