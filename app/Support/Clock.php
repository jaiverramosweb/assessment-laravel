<?php

namespace App\Support;

/**
 * Current time in integer milliseconds UTC (the unit every date column uses).
 * Tests can freeze it with `Clock::freeze()`.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function nowMs(): int
    {
        return self::$frozen ?? (int) floor(microtime(true) * 1000);
    }

    public static function freeze(?int $ms): void
    {
        self::$frozen = $ms;
    }
}
