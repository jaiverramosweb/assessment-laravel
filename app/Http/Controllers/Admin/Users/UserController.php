<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AdminUsers;
use App\Services\Assessments\Assessments;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** "Usuarios" tab: list, create, change access, reset another admin's password, deactivate and reactivate. */
class UserController extends Controller
{
    /** Access fields of the form (see AdminAccess::fromInput). */
    private const ACCESS_FIELDS = ['access', 'permissions', 'reportsScope', 'reportsClientIds'];

    public function index(Request $request)
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => AdminUsers::list($request->attributes->get('admin')->id),
            // For the "Ver reportes" client selector.
            'clients' => Assessments::clientOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $result = AdminUsers::create(
            $request->only(['name', 'email', 'password', 'password_confirmation', ...self::ACCESS_FIELDS]),
            $request->attributes->get('admin')->id,
            $request->ip(),
        );
        if (! $result['ok']) {
            return back(303)->withErrors($result['errors'])->withInput($request->only(['name', 'email']));
        }

        return back(303)->with('success', "Usuario creado: {$result['admin']->email}. Ya puede ingresar con la contraseña que le asignó.");
    }

    public function access(Request $request, string $userId)
    {
        [$target, $error] = $this->other($request, $userId, 'No puede cambiar su propio nivel de acceso.');
        if ($error) {
            return back(303)->with('error', $error);
        }

        $result = AdminUsers::updateAccess($target, $request->only(self::ACCESS_FIELDS), $request->attributes->get('admin')->id, $request->ip());
        if (! $result['ok']) {
            return back(303)->withErrors($result['errors']);
        }

        return back(303)->with('success', "Acceso de {$target->email} actualizado.");
    }

    public function password(Request $request, string $userId)
    {
        [$target, $error] = $this->other($request, $userId, 'Para cambiar su propia contraseña use "Mi cuenta".');
        if ($error) {
            return back(303)->with('error', $error);
        }

        $result = AdminUsers::resetPassword($target, $request->only(['password', 'password_confirmation']), $request->attributes->get('admin')->id, $request->ip());
        if (! $result['ok']) {
            return back(303)->withErrors($result['errors']);
        }

        return back(303)->with('success', "Contraseña de {$target->email} cambiada. Sus sesiones abiertas se cerraron.");
    }

    public function deactivate(Request $request, string $userId)
    {
        [$target, $error] = $this->other($request, $userId, 'No puede desactivar su propio usuario.');
        if ($error) {
            return back(303)->with('error', $error);
        }
        AdminUsers::setActive($target, false, $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('success', "Usuario {$target->email} desactivado. Ya no puede ingresar.");
    }

    public function activate(Request $request, string $userId)
    {
        [$target, $error] = $this->other($request, $userId, 'Su usuario ya está activo.');
        if ($error) {
            return back(303)->with('error', $error);
        }
        AdminUsers::setActive($target, true, $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('success', "Usuario {$target->email} activado.");
    }

    /**
     * Another admin (never the logged-in one: the self check also keeps at least one active admin).
     *
     * @return array{0: ?AdminUser, 1: ?string}
     */
    private function other(Request $request, string $userId, string $selfMessage): array
    {
        $target = AdminUser::find($userId);
        if (! $target) {
            return [null, 'El usuario no existe.'];
        }
        if ($target->id === $request->attributes->get('admin')->id) {
            return [null, $selfMessage];
        }

        return [$target, null];
    }
}
