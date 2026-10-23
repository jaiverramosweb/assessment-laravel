<?php

namespace App\Services\Ai\Analysis;

use App\Services\Ai\AiSettings;
use App\Support\Clock;
use Illuminate\Support\Facades\Cache;

/**
 * Pending and failed state of the queued analyses, per assessment and key (question id or
 * "global"). Kept in the cache store (file on the host, array in tests), with expirations so a
 * worker that died never leaves a key pending forever.
 */
final class AiJobState
{
    /** Seconds an error stays visible on the reports page. */
    public const ERROR_TTL = 3600;

    /** Marks the key as pending. False when it already was (another analysis is queued or running). */
    public static function markPending(string $assessmentId, string $key): bool
    {
        return Cache::add(self::pendingKey($assessmentId, $key), Clock::nowMs(), self::pendingTtl());
    }

    public static function clearPending(string $assessmentId, string $key): void
    {
        Cache::forget(self::pendingKey($assessmentId, $key));
    }

    public static function setError(string $assessmentId, string $key, string $message): void
    {
        Cache::put(self::errorKey($assessmentId, $key), $message, self::ERROR_TTL);
    }

    public static function clearError(string $assessmentId, string $key): void
    {
        Cache::forget(self::errorKey($assessmentId, $key));
    }

    /**
     * @param  string[]  $keys
     * @return string[] keys with a queued or running analysis
     */
    public static function pending(string $assessmentId, array $keys): array
    {
        if (! $keys) {
            return [];
        }
        $values = Cache::many(array_map(fn ($k) => self::pendingKey($assessmentId, $k), $keys));

        return array_values(array_filter($keys, fn ($k) => $values[self::pendingKey($assessmentId, $k)] !== null));
    }

    /**
     * @param  string[]  $keys
     * @return array<string, string> key => error message of its last failed analysis
     */
    public static function errors(string $assessmentId, array $keys): array
    {
        if (! $keys) {
            return [];
        }
        $values = Cache::many(array_map(fn ($k) => self::errorKey($assessmentId, $k), $keys));
        $out = [];
        foreach ($keys as $k) {
            $message = $values[self::errorKey($assessmentId, $k)];
            if (is_string($message)) {
                $out[$k] = $message;
            }
        }

        return $out;
    }

    /** Longest time a job may stay queued or running (seconds). */
    public static function pendingTtl(): int
    {
        return self::jobTimeout() + 600;
    }

    /**
     * Job timeout (seconds): an analysis may make several sequential calls (blocks plus the
     * consolidation), each bounded by AI_TIMEOUT_MS and retried up to twice.
     */
    public static function jobTimeout(): int
    {
        $callSeconds = (int) ceil(max(1000, AiSettings::timeoutMs()) / 1000);

        return $callSeconds * 6 + 60;
    }

    private static function pendingKey(string $assessmentId, string $key): string
    {
        return "ia:pendiente:{$assessmentId}:{$key}";
    }

    private static function errorKey(string $assessmentId, string $key): string
    {
        return "ia:error:{$assessmentId}:{$key}";
    }
}
