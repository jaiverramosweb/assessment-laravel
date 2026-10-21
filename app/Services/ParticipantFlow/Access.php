<?php

namespace App\Services\ParticipantFlow;

use App\Auth\ParticipantSessions;
use App\Auth\Passwords;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Submission;
use App\Security\AuthRateLimiter;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Participant access rules (port of the original services/access.ts).
 *
 * Difference with the original: Node serialized attempts per IP/email with an in-memory lock
 * (`withKeyLock`). PHP runs one process per request, so there is no shared memory: the failure
 * counters are updated in a transaction and participant/submission creation relies on the unique
 * indexes (insert-or-ignore). Under truly simultaneous wrong attempts a few extra guesses can be
 * evaluated before the lock is recorded.
 */
final class Access
{
    /**
     * Can it be answered now? Only published assessments inside the date range.
     *
     * @param  Assessment|array{status: string, starts_at: int, ends_at: int}  $a
     * @return 'no_disponible'|'no_iniciado'|'cerrado'|'abierto'
     */
    public static function availability(Assessment|array $a, ?int $now = null): string
    {
        $now ??= Clock::nowMs();
        if ($a['status'] !== 'publicado') {
            return 'no_disponible';
        }
        if ($now < $a['starts_at']) {
            return 'no_iniciado';
        }
        if ($now >= $a['ends_at']) {
            return 'cerrado';
        }

        return 'abierto';
    }

    public static function findBySlug(string $slug): ?Assessment
    {
        if (! preg_match('/^[a-z0-9]{6,32}$/', $slug)) {
            return null;
        }

        return Assessment::where('slug', $slug)->first();
    }

    /** Public assessment by slug. Drafts are treated as nonexistent (404). */
    public static function requirePublic(string $slug): Assessment
    {
        $a = self::findBySlug($slug);
        if (! $a || $a->status === 'borrador') {
            abort(404, 'Este assessment no existe o no está disponible.');
        }

        return $a;
    }

    /**
     * Validates a participant's entry (shared password + email).
     * - Rate limiting per IP and per (email, IP), per assessment.
     * - "invitados" mode: the email must be loaded. "abierto" mode: registered when new.
     * - One submission per email: once delivered, the person cannot enter again.
     * - Creates the (in progress) submission the first time, which locks question editing.
     *
     * @return array{ok: true, participantId: string}|array{ok: false, reason: string, until?: int, submittedAt?: int}
     */
    public static function enter(Assessment $a, string $email, string $password, ?string $name, string $ip, ?int $now = null): array
    {
        $now ??= Clock::nowMs();
        $avail = self::availability($a, $now);
        if ($avail !== 'abierto') {
            return ['ok' => false, 'reason' => $avail];
        }

        $keys = AuthRateLimiter::participantLimitKeys("acceso:{$a->id}", $ip, $email);
        $blocked = AuthRateLimiter::lockedUntil($keys, $now);
        if ($blocked) {
            return ['ok' => false, 'reason' => 'locked', 'until' => $blocked];
        }

        $fail = function (string $reason) use ($keys, $now): array {
            $until = AuthRateLimiter::recordFailure($keys, $now);

            return $until ? ['ok' => false, 'reason' => 'locked', 'until' => $until] : ['ok' => false, 'reason' => $reason];
        };

        if (! $a->access_password_hash || ! Passwords::verify($a->access_password_hash, $password)) {
            return $fail('invalid_password');
        }

        $participantId = self::participantId($a->id, $email);
        if (! $participantId) {
            if ($a->access_mode === 'invitados') {
                return $fail('not_invited');
            }
            if (! $name) {
                return ['ok' => false, 'reason' => 'name_required'];
            }
            // Open mode: the person is registered. Insert-or-ignore covers two simultaneous entries.
            DB::table('participants')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'assessment_id' => $a->id,
                'email' => $email,
                'name' => $name,
                'source' => 'abierto',
                'created_at' => $now,
            ]);
            $participantId = self::participantId($a->id, $email);
        }

        $existing = Submission::where('participant_id', $participantId)->first(['submitted_at']);
        if ($existing?->submitted_at) {
            return ['ok' => false, 'reason' => 'already_submitted', 'submittedAt' => $existing->submitted_at];
        }

        AuthRateLimiter::clearFailures([$keys[1]['key']]);
        if (! $existing) {
            DB::table('submissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'participant_id' => $participantId,
                'assessment_id' => $a->id,
                'started_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return ['ok' => true, 'participantId' => $participantId];
    }

    private static function participantId(string $assessmentId, string $email): ?string
    {
        return Participant::where('assessment_id', $assessmentId)->where('email', $email)->value('id');
    }

    /**
     * Check made on EVERY participant request: valid session for this assessment, not yet
     * delivered and inside the dates.
     *
     * @return array{kind: 'ok', assessment: Assessment, participant: array{id: string, name: string, email: string}, submission: Submission, sessionExpiresAt: int}|array{kind: string}
     */
    public static function context(?string $token, string $slug, int $sessionHours, ?int $now = null): array
    {
        $now ??= Clock::nowMs();
        if (! $token) {
            return ['kind' => 'sin_sesion'];
        }
        $session = ParticipantSessions::validate($token, $sessionHours, $now);
        if (! $session) {
            return ['kind' => 'sin_sesion'];
        }

        $participant = Participant::find($session['participantId'], ['id', 'name', 'email', 'assessment_id']);
        $assessment = $participant ? Assessment::find($participant->assessment_id) : null;
        $submission = $participant ? Submission::where('participant_id', $participant->id)->first() : null;

        // The session must belong to the assessment in the URL.
        if (! $participant || ! $assessment || ! $submission || $assessment->slug !== $slug) {
            return ['kind' => 'sin_sesion'];
        }
        if ($submission->submitted_at) {
            return ['kind' => 'entregado'];
        }
        $avail = self::availability($assessment, $now);
        if ($avail !== 'abierto') {
            return ['kind' => $avail];
        }

        return [
            'kind' => 'ok',
            'assessment' => $assessment,
            'participant' => ['id' => $participant->id, 'name' => $participant->name, 'email' => $participant->email],
            'submission' => $submission,
            'sessionExpiresAt' => $session['expiresAt'],
        ];
    }
}
