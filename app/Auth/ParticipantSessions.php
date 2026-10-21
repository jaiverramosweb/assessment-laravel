<?php

namespace App\Auth;

use App\Models\ParticipantSession;
use App\Support\Clock;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Participant session: created after validating password + email; allows saving drafts and
 * coming back later. The cookie is scoped to the assessment path (/a/{slug}), so a person
 * can have sessions in several assessments at once.
 */
final class ParticipantSessions
{
    public const COOKIE = 'participant_session';

    private const HOUR = 3600 * 1000;

    /** @return array{token: string, expiresAt: int} */
    public static function create(string $participantId, int $hours, ?int $now = null): array
    {
        $now ??= Clock::nowMs();
        $token = Tokens::generate();
        $expiresAt = $now + $hours * self::HOUR;
        ParticipantSession::create([
            'id' => Tokens::hash($token),
            'participant_id' => $participantId,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return ['token' => $token, 'expiresAt' => $expiresAt];
    }

    /**
     * Returns the session's participant id (and renews the expiration), or null.
     *
     * @return array{participantId: string, expiresAt: int}|null
     */
    public static function validate(string $token, int $hours, ?int $now = null): ?array
    {
        $now ??= Clock::nowMs();
        $session = ParticipantSession::find(Tokens::hash($token));
        if (! $session) {
            return null;
        }

        if ($session->expires_at <= $now) {
            $session->delete();

            return null;
        }

        $expiresAt = $session->expires_at;
        if ($expiresAt - $now < ($hours * self::HOUR) / 2) {
            $expiresAt = $now + $hours * self::HOUR;
            $session->update(['expires_at' => $expiresAt]);
        }

        return ['participantId' => $session->participant_id, 'expiresAt' => $expiresAt];
    }

    public static function invalidateAll(string $participantId): void
    {
        ParticipantSession::where('participant_id', $participantId)->delete();
    }

    public static function deleteExpired(?int $now = null): void
    {
        ParticipantSession::where('expires_at', '<', $now ?? Clock::nowMs())->delete();
    }

    public static function cookie(string $slug, string $token, int $expiresAt): Cookie
    {
        return SessionCookies::make(self::COOKIE, $token, $expiresAt, self::path($slug));
    }

    public static function forgetCookie(string $slug): Cookie
    {
        return SessionCookies::forget(self::COOKIE, self::path($slug));
    }

    private static function path(string $slug): string
    {
        return '/a/'.$slug;
    }
}
