<?php

namespace App\Security;

use App\Models\AuthAttempt;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Rate limiting with temporary lockout, stored in the database so it survives restarts.
 * Each key counts failed attempts inside a time window; when it reaches the maximum, the key
 * is locked for `lockMs`.
 *
 * Policies are arrays: ['maxFailures' => int, 'windowMs' => int, 'lockMs' => int].
 * Keys are arrays: ['key' => string, 'policy' => array].
 */
final class AuthRateLimiter
{
    private const MIN = 60 * 1000;

    /** Per email: few attempts, to stop attacks on a specific account. */
    public const EMAIL_POLICY = ['maxFailures' => 5, 'windowMs' => 15 * self::MIN, 'lockMs' => 15 * self::MIN];

    /** Per IP: more room (several people can share an office IP). */
    public const IP_POLICY = ['maxFailures' => 20, 'windowMs' => 15 * self::MIN, 'lockMs' => 15 * self::MIN];

    /**
     * Participant login per IP: in a company many people go out to the internet with the
     * same IP and answer at the same time, so the margin is larger.
     */
    public const PARTICIPANT_IP_POLICY = ['maxFailures' => 50, 'windowMs' => 15 * self::MIN, 'lockMs' => 15 * self::MIN];

    /** IP and email keys for a scope (e.g. "admin-login"). */
    public static function limitKeys(string $scope, string $ip, string $email, array $ipPolicy = self::IP_POLICY): array
    {
        return [
            ['key' => "{$scope}:ip:{$ip}", 'policy' => $ipPolicy],
            ['key' => "{$scope}:email:".mb_strtolower(trim($email)), 'policy' => self::EMAIL_POLICY],
        ];
    }

    /**
     * Keys for participant login. The password is shared, so the per-email limit does not
     * protect a personal secret: it counts per (email, IP) so nobody can lock another person
     * out just by knowing their email.
     */
    public static function participantLimitKeys(string $scope, string $ip, string $email): array
    {
        return [
            ['key' => "{$scope}:ip:{$ip}", 'policy' => self::PARTICIPANT_IP_POLICY],
            ['key' => "{$scope}:email:".mb_strtolower(trim($email)).":ip:{$ip}", 'policy' => self::EMAIL_POLICY],
        ];
    }

    /** Until when the most restricted key is locked (ms), or null when none is. */
    public static function lockedUntil(array $keys, ?int $now = null): ?int
    {
        if ($keys === []) {
            return null;
        }
        $now ??= Clock::nowMs();
        $latest = null;
        foreach (AuthAttempt::whereIn('key', array_column($keys, 'key'))->pluck('locked_until') as $until) {
            if ($until && $until > $now && (! $latest || $until > $latest)) {
                $latest = $until;
            }
        }

        return $latest;
    }

    /** Records a failure on every key. Returns the lock end (ms) when any key got locked. */
    public static function recordFailure(array $keys, ?int $now = null): ?int
    {
        $now ??= Clock::nowMs();
        // The rows exist before the transaction locks them: locking a missing row on MySQL takes a
        // gap lock, and simultaneous first failures on a new key then deadlock on the insert.
        DB::table('auth_attempts')->insertOrIgnore(array_map(
            fn ($k) => ['key' => $k['key'], 'failures' => 0, 'window_start' => $now, 'locked_until' => null],
            $keys,
        ));

        return DB::transaction(function () use ($keys, $now) {
            $locked = null;
            foreach ($keys as ['key' => $key, 'policy' => $policy]) {
                // Locked (MySQL) so simultaneous failures are all counted; SQLite serializes writes.
                $row = AuthAttempt::lockForUpdate()->find($key);
                $windowExpired = ! $row || $now - $row->window_start > $policy['windowMs'];
                $failures = $windowExpired ? 1 : $row->failures + 1;

                if ($failures >= $policy['maxFailures']) {
                    $until = $now + $policy['lockMs'];
                    // Locking resets the counter: after the lock there is another batch of attempts.
                    self::upsert($key, 0, $now, $until);
                    if (! $locked || $until > $locked) {
                        $locked = $until;
                    }
                } else {
                    self::upsert($key, $failures, $windowExpired ? $now : $row->window_start, $row?->locked_until);
                }
            }

            return $locked;
        }, 3); // Two first failures on a new key can deadlock on MySQL: retried.
    }

    /** After a successful login the email counter is cleared (not the IP one). */
    public static function clearFailures(array $keys): void
    {
        if ($keys !== []) {
            AuthAttempt::whereIn('key', $keys)->delete();
        }
    }

    /** Periodic cleanup of records that no longer lock or count. */
    public static function purgeStale(int $olderThanMs, ?int $now = null): void
    {
        $now ??= Clock::nowMs();
        AuthAttempt::where('window_start', '<', $now - $olderThanMs)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->delete();
    }

    /** Spanish message with the remaining lock minutes. */
    public static function lockMessage(int $until, ?int $now = null): string
    {
        $now ??= Clock::nowMs();
        $minutes = max(1, (int) ceil(($until - $now) / self::MIN));

        return "Demasiados intentos fallidos. Intente de nuevo en {$minutes} ".($minutes === 1 ? 'minuto' : 'minutos').'.';
    }

    private static function upsert(string $key, int $failures, int $windowStart, ?int $lockedUntil): void
    {
        AuthAttempt::updateOrCreate(
            ['key' => $key],
            ['failures' => $failures, 'window_start' => $windowStart, 'locked_until' => $lockedUntil],
        );
    }
}
