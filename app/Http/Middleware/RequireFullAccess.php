<?php

namespace App\Http\Middleware;

use App\Auth\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default deny for limited admins: every /admin route requires full access unless the route
 * opts out with `withoutMiddleware('admin.full')` and applies its own check (see routes/admin/).
 */
class RequireFullAccess
{
    public const MESSAGE = 'Su usuario no tiene permiso para esta sección.';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->attributes->get('admin');
        if ($admin && ! AdminAccess::isFull($admin)) {
            abort(403, self::MESSAGE);
        }

        return $next($request);
    }
}
