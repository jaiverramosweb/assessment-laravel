<?php

namespace App\Http\Middleware;

use App\Auth\ParticipantSessions;
use App\Services\ParticipantFlow\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Participant context for /a/{slug}/responder (port of the original `requireParticipant`).
 * Runs on the page load and on EVERY action: without a valid session, already delivered or out
 * of dates, it redirects. With activity the session is renewed and the cookie is sent again with
 * the new expiration. The context is available as `$request->attributes->get('participant')`.
 *
 * Autosave requests (JSON) get `{type: 'redirect', location}` instead of an HTTP redirect, like
 * the original SvelteKit action result, so the browser script decides where to go.
 */
class LoadParticipantSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('slug');
        $token = $request->cookie(ParticipantSessions::COOKIE);
        $ctx = Access::context(is_string($token) ? $token : null, $slug, (int) config('assessments.participant_session_hours'));

        if ($ctx['kind'] !== 'ok') {
            $location = $ctx['kind'] === 'entregado' ? "/a/{$slug}/entregado" : "/a/{$slug}";

            return self::wantsActionJson($request)
                ? response()->json(['type' => 'redirect', 'location' => $location])
                : redirect($location, 303);
        }

        $request->attributes->set('participant', $ctx);
        $response = $next($request);

        // The controller may have already set the cookie (e.g. cleared it after submitting).
        $alreadySet = collect($response->headers->getCookies())
            ->contains(fn ($c) => $c->getName() === ParticipantSessions::COOKIE);
        if (! $alreadySet) {
            $response->headers->setCookie(ParticipantSessions::cookie($slug, $token, $ctx['sessionExpiresAt']));
        }

        return $response;
    }

    /** Autosave calls made with fetch (JSON), not Inertia visits. */
    public static function wantsActionJson(Request $request): bool
    {
        return $request->wantsJson() && ! $request->header('X-Inertia');
    }
}
