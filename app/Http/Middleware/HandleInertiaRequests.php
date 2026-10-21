<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props every page receives:
     * - `admin`: the logged-in admin ({id, email, name}) or null.
     * - `flash`: one-shot messages set with `->with('success'|'error', '...')`.
     * Validation errors arrive as `errors` (Inertia); a general form error uses the key `form`.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'admin' => fn () => $request->attributes->get('admin')?->toSessionArray(),
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
