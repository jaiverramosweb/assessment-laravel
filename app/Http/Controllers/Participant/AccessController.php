<?php

namespace App\Http\Controllers\Participant;

use App\Auth\ParticipantSessions;
use App\Http\Controllers\Controller;
use App\Security\AuthRateLimiter;
use App\Services\ParticipantFlow\Access;
use App\Services\ParticipantFlow\Dates;
use App\Services\ParticipantFlow\EnterForm;
use App\Services\Shared\Brand;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** /a/{slug}: access form (shared password + email, and name in open mode). */
class AccessController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $a = Access::requirePublic($slug);

        // With a valid session, continue where the person left off.
        $token = $request->cookie(ParticipantSessions::COOKIE);
        $ctx = Access::context(is_string($token) ? $token : null, $a->slug, self::sessionHours());
        if ($ctx['kind'] === 'ok') {
            return redirect("/a/{$a->slug}/responder", 303);
        }

        return Inertia::render('Participant/Access', [
            // Only public data of the assessment.
            'assessment' => [
                'title' => $a->title,
                'description' => $a->description,
                'accessMode' => $a->access_mode,
                'startsAt' => $a->starts_at,
                'endsAt' => $a->ends_at,
                'timezone' => $a->timezone,
                ...Brand::forParticipant($a->slug, $a->client_id),
            ],
            'availability' => Access::availability($a),
            // Result of the last attempt: {values, error, needName}, or null.
            'form' => $request->session()->get('participantForm'),
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $a = Access::requirePublic($slug);
        $values = ['email' => (string) $request->input('email', ''), 'name' => (string) $request->input('name', '')];
        $back = fn (string $error, bool $needName = false) => redirect("/a/{$a->slug}", 303)
            ->with('participantForm', ['values' => $values, 'error' => $error, 'needName' => $needName]);

        $parsed = EnterForm::validate($request->only(['password', 'email', 'name']));
        if (! $parsed['ok']) {
            return $back($parsed['error']);
        }

        $result = Access::enter($a, $parsed['email'], $parsed['password'], $parsed['name'], (string) $request->ip());
        if (! $result['ok']) {
            $tz = $a->timezone;
            $message = match ($result['reason']) {
                'invalid_password' => 'La contraseña de acceso no es correcta.',
                'not_invited' => 'Este email no está en la lista de invitados. Use el mismo correo en el que recibió la invitación.',
                'name_required' => 'Es su primer ingreso: escriba su nombre para registrarse.',
                'no_disponible' => 'Este assessment no está disponible.',
                'no_iniciado' => 'Aún no está disponible. Podrá ingresar desde el '.Dates::long($a->starts_at, $tz).'.',
                'cerrado' => 'Este assessment ya cerró.',
                'locked' => AuthRateLimiter::lockMessage($result['until']),
                'already_submitted' => 'Este email ya entregó sus respuestas el '.Dates::long($result['submittedAt'], $tz).'. Solo se permite una entrega por persona.',
                default => 'No fue posible ingresar.',
            };

            return $back($message, $result['reason'] === 'name_required');
        }

        ParticipantSessions::deleteExpired();
        $session = ParticipantSessions::create($result['participantId'], self::sessionHours());

        return redirect("/a/{$a->slug}/responder", 303)
            ->withCookie(ParticipantSessions::cookie($a->slug, $session['token'], $session['expiresAt']));
    }

    private static function sessionHours(): int
    {
        return (int) config('assessments.participant_session_hours');
    }
}
