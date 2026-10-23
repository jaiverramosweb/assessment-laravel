<?php

namespace App\Http\Middleware;

use App\Auth\AdminAccess;
use App\Models\Assessment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report routes: the admin must be allowed to see the reports of the client in the URL.
 * `admin.reports:assessment` reads the client of the assessment {id}; `admin.reports:client`
 * takes {id} as the client. A missing record is left to the controller (404).
 */
class RequireReportAccess
{
    public function handle(Request $request, Closure $next, string $target): Response
    {
        $admin = $request->attributes->get('admin');
        if ($admin && ! AdminAccess::isFull($admin)) {
            $id = (string) $request->route('id');
            if ($target === 'client') {
                $allowed = AdminAccess::canViewReportsOf($admin, $id);
            } else {
                $assessment = Assessment::query()->select(['id', 'client_id'])->find($id);
                $allowed = ! $assessment || AdminAccess::canViewReportsOf($admin, $assessment->client_id);
            }
            if (! $allowed) {
                abort(403, RequireFullAccess::MESSAGE);
            }
        }

        return $next($request);
    }
}
