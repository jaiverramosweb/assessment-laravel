<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminSessions;
use App\Auth\SafeRedirect;
use App\Auth\SessionCookies;
use App\Http\Controllers\Controller;
use App\Security\Audit;
use App\Security\AuthRateLimiter;
use App\Services\AdminAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class SessionController extends Controller
{
    public function create(Request $request)
    {
        if ($request->attributes->get('admin')) {
            return redirect(SafeRedirect::admin($request->query('destino')), 303);
        }

        return Inertia::render('Admin/Login');
    }

    public function store(Request $request)
    {
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $validator = Validator::make(
            ['email' => $email, 'password' => $request->input('password')],
            [
                'email' => ['required', 'string', 'max:254', 'email'],
                // At login the length policy is not enforced: it is only compared with the hash.
                'password' => ['required', 'string', 'max:200'],
            ],
            [
                'email.required' => 'Escriba un email.',
                'email.max' => 'El email es demasiado largo.',
                'email.email' => 'Escriba un email válido.',
                'password.required' => 'Escriba la contraseña.',
                'password.max' => 'La contraseña es demasiado larga.',
            ],
        );
        if ($validator->fails()) {
            return back(303)->withErrors(['form' => $validator->errors()->first()])->withInput(['email' => $email]);
        }

        $result = AdminAuth::authenticate($email, (string) $request->input('password'), (string) $request->ip());
        if (! $result['ok']) {
            $message = $result['reason'] === 'locked'
                ? AuthRateLimiter::lockMessage($result['until'])
                : 'Email o contraseña incorrectos.';

            return back(303)->withErrors(['form' => $message])->withInput(['email' => $email]);
        }

        AdminSessions::deleteExpired();
        $session = AdminSessions::create($result['admin']->id, (int) config('assessments.admin_session_hours'));
        $request->session()->regenerate();

        return redirect(SafeRedirect::admin($request->query('destino')), 303)
            ->withCookie(SessionCookies::make(AdminSessions::COOKIE, $session['token'], $session['expiresAt']));
    }

    /** POST only: a GET (e.g. an <img src="/admin/logout">) must not be able to close the session. */
    public function destroy(Request $request)
    {
        $token = $request->cookie(AdminSessions::COOKIE);
        if (is_string($token) && $token !== '') {
            AdminSessions::invalidate($token);
        }
        $admin = $request->attributes->get('admin');
        if ($admin) {
            Audit::log($admin->id, 'admin.logout', ip: $request->ip());
        }
        $request->session()->invalidate();

        return redirect('/admin/login', 303)->withCookie(SessionCookies::forget(AdminSessions::COOKIE));
    }
}
