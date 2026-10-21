<?php

namespace App\Auth;

use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Support\Clock;

final class AdminSessions
{
    public const COOKIE = 'admin_session';

    private const HOUR = 3600 * 1000;

    /** Maximum lifetime of an admin session, even with continuous use (7 days). */
    public const MAX_SESSION_HOURS = 7 * 24;

    /**
     * Creates a session and returns the token (it only travels in the cookie, never in the DB).
     *
     * @return array{token: string, expiresAt: int}
     */
    public static function create(string $adminId, int $hours, ?int $now = null): array
    {
        $now ??= Clock::nowMs();
        $token = Tokens::generate();
        $expiresAt = $now + $hours * self::HOUR;
        AdminSession::create([
            'id' => Tokens::hash($token),
            'admin_id' => $adminId,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return ['token' => $token, 'expiresAt' => $expiresAt];
    }

    /**
     * Validates the cookie token. Deletes the session when it expired.
     * Sliding expiration: once half its life has passed, it is extended.
     *
     * @return array{admin: AdminUser, expiresAt: int}|null
     */
    public static function validate(string $token, int $hours, ?int $now = null): ?array
    {
        $now ??= Clock::nowMs();
        $session = AdminSession::with('admin')->find(Tokens::hash($token));
        if (! $session || ! $session->admin) {
            return null;
        }

        // Besides the inactivity expiration, a session never lasts more than MAX_SESSION_HOURS.
        $absoluteEnd = $session->created_at + self::MAX_SESSION_HOURS * self::HOUR;
        if ($session->expires_at <= $now || $absoluteEnd <= $now) {
            $session->delete();

            return null;
        }

        $expiresAt = $session->expires_at;
        if ($expiresAt - $now < ($hours * self::HOUR) / 2) {
            $expiresAt = min($now + $hours * self::HOUR, $absoluteEnd);
            $session->update(['expires_at' => $expiresAt]);
        }

        return ['admin' => $session->admin, 'expiresAt' => $expiresAt];
    }

    public static function invalidate(string $token): void
    {
        AdminSession::whereKey(Tokens::hash($token))->delete();
    }

    /** Closes every session of an admin (e.g. after a password change). */
    public static function invalidateAll(string $adminId): void
    {
        AdminSession::where('admin_id', $adminId)->delete();
    }

    public static function deleteExpired(?int $now = null): void
    {
        AdminSession::where('expires_at', '<', $now ?? Clock::nowMs())->delete();
    }
}
