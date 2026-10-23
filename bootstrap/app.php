<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LoadAdminSession;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireFullAccess;
use App\Http\Middleware\RequireReportAccess;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // Session tokens are random and opaque; they are stored as SHA-256, never in clear.
        $middleware->encryptCookies(except: ['admin_session', 'participant_session']);
        $middleware->web(append: [LoadAdminSession::class, HandleInertiaRequests::class]);
        $middleware->alias([
            'admin' => RequireAdmin::class,
            'admin.full' => RequireFullAccess::class,
            'admin.reports' => RequireReportAccess::class,
        ]);
        // Password confirmation must be compared exactly like the password (which is never trimmed).
        $middleware->trimStrings(except: ['confirm']);

        // Behind a reverse proxy, list its IPs (comma separated) so the real client IP is used.
        $proxies = env('TRUSTED_PROXIES');
        if ($proxies) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errors render the Inertia error page with a generic message; the real error is logged.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if (! in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }
            if ($status === 500 && config('app.debug')) {
                return $response;
            }
            if ($status === 419) {
                return back(303)->with('error', 'La página venció. Intente de nuevo.');
            }

            $message = match ($status) {
                500, 503 => 'Ocurrió un error inesperado. Intente de nuevo.',
                default => $e->getMessage() ?: null,
            };

            return Inertia::render('Error', ['status' => $status, 'message' => $message])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
