<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Admin\Clients\ClientBrandController;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Services\Clients\ClientService;
use Illuminate\Http\Request;

/**
 * Logo of an assessment's client (public: it appears on the access screen).
 * Like the access screen, a draft is treated as nonexistent.
 */
class LogoController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $a = preg_match('/^[a-z0-9]{6,32}$/', $slug)
            ? Assessment::query()->where('slug', $slug)->first(['id', 'client_id', 'status'])
            : null;
        $logo = $a && $a->status !== 'borrador' ? ClientService::readLogo($a->client_id) : null;
        if (! $logo) {
            abort(404, 'Sin logo');
        }

        $response = response($logo['data'], 200, [
            // The type was detected from the content on upload (only PNG, JPEG or WebP).
            'Content-Type' => $logo['mime'],
            'Content-Security-Policy' => ClientBrandController::LOGO_CSP,
        ]);
        // The versioned URL (?v=<update time>) changes with each new logo, so it can be cached forever.
        $response->headers->set('Cache-Control', $request->query->has('v')
            ? 'public, max-age=31536000, immutable'
            : 'no-cache');

        return $response;
    }
}
