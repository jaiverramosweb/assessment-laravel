<?php

namespace App\Http\Controllers\Admin\Users;

use App\Auth\AdminSessions;
use App\Http\Controllers\Controller;
use App\Services\AdminUsers;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** "Mi cuenta": the logged-in admin changes their own password. */
class AccountController extends Controller
{
    public function show(Request $request)
    {
        return Inertia::render('Admin/Account', [
            'admin' => $request->attributes->get('admin')->toSessionArray(),
        ]);
    }

    public function password(Request $request)
    {
        $result = AdminUsers::changeOwnPassword(
            $request->attributes->get('admin'),
            $request->only(['current_password', 'password', 'password_confirmation']),
            (string) $request->cookie(AdminSessions::COOKIE),
            (string) $request->ip(),
        );
        if (! $result['ok']) {
            return back(303)->withErrors($result['errors']);
        }

        return back(303)->with('success', 'Contraseña cambiada. Sus otras sesiones se cerraron.');
    }
}
