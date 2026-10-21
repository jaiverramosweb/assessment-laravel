<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Security headers on every response (without overwriting what a route already set). */
class SecurityHeaders
{
    public const HEADERS = [
        // Prevents the app from being shown inside an iframe (clickjacking).
        'X-Frame-Options' => 'DENY',
        // The browser must not guess MIME types (avoids running uploads as scripts).
        'X-Content-Type-Options' => 'nosniff',
        // Do not leak the full URL to other sites (the assessment slug is semi-secret).
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    /**
     * Production CSP. Assets are same-origin files built by Vite, and Inertia sends the page data
     * in an attribute, so no inline scripts are needed. Svelte sets inline style attributes.
     */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
        ."object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        foreach (self::HEADERS as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }
        // In development Vite serves scripts from its own port, so the CSP is production-only.
        if (app()->isProduction() && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CSP);
        }
        // HSTS only makes sense (and is only valid) over HTTPS.
        if ($request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
