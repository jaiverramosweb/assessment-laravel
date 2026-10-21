<?php

namespace App\Http\Middleware;

use App\Auth\AdminSessions;
use App\Auth\SessionCookies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads the admin session (when there is a cookie) and renews its expiration.
 * The admin is available as `$request->attributes->get('admin')` (AdminUser or null).
 */
class LoadAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('admin', null);
        $token = $request->cookie(AdminSessions::COOKIE);
        $cookie = null;

        if (is_string($token) && $token !== '') {
            $session = AdminSessions::validate($token, (int) config('assessments.admin_session_hours'));
            if ($session) {
                $request->attributes->set('admin', $session['admin']);
                $cookie = SessionCookies::make(AdminSessions::COOKIE, $token, $session['expiresAt']);
            } else {
                $cookie = SessionCookies::forget(AdminSessions::COOKIE);
            }
        }

        $response = $next($request);
        // The controller may have replaced or removed the cookie (login/logout): keep its choice.
        if ($cookie && ! self::hasCookie($response, AdminSessions::COOKIE)) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    private static function hasCookie(Response $response, string $name): bool
    {
        foreach ($response->headers->getCookies() as $c) {
            if ($c->getName() === $name) {
                return true;
            }
        }

        return false;
    }
}
