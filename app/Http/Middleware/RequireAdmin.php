<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the admin panel. Without a session it redirects to the login keeping the destination.
 * Admin pages must not be cached (browser or proxies).
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('admin')) {
            $destino = '/'.ltrim($request->path(), '/');
            $query = $request->getQueryString();
            if ($query) {
                $destino .= '?'.$query;
            }

            return redirect('/admin/login?destino='.rawurlencode($destino), 303);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
