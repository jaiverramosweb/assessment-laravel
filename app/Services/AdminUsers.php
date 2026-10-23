<?php

namespace App\Services;

use App\Auth\AdminAccess;
use App\Auth\AdminSessions;
use App\Auth\Passwords;
use App\Models\AdminUser;
use App\Security\Audit;
use App\Security\AuthRateLimiter;
use App\Support\Clock;
use Illuminate\Support\Facades\Validator;

/**
 * Admin users managed from the panel ("Usuarios" tab and "Mi cuenta"). Each admin has full or
 * limited access (see AdminAccess). The terminal command (admin:create) creates full admins.
 */
final class AdminUsers
{
    public const PASSWORD_SCOPE = 'admin-password';

    private const PASSWORD_RULES = ['required', 'string', 'min:10', 'max:200', 'confirmed'];

    private const MESSAGES = [
        'name.required' => 'El nombre es muy corto.',
        'name.min' => 'El nombre es muy corto.',
        'name.max' => 'El nombre es muy largo.',
        'email.required' => 'Escriba un email.',
        'email.max' => 'El email es demasiado largo.',
        'email.email' => 'Escriba un email válido.',
        'password.required' => 'La contraseña debe tener al menos 10 caracteres.',
        'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
        'password.max' => 'La contraseña es demasiado larga.',
        'password.confirmed' => 'Las contraseñas no coinciden.',
        'current_password.required' => 'Escriba su contraseña actual.',
    ];

    /**
     * @return list<array{id: string, name: string, email: string, lastLoginAt: ?int, createdAt: int, active: bool, isSelf: bool, access: array}>
     */
    public static function list(string $selfId): array
    {
        return AdminUser::orderBy('name')->orderBy('email')->get()->map(fn (AdminUser $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'lastLoginAt' => $u->last_login_at,
            'createdAt' => $u->created_at,
            'active' => $u->isActive(),
            'isSelf' => $u->id === $selfId,
            'access' => AdminAccess::toArray($u),
        ])->values()->all();
    }

    /**
     * Validates and creates an admin. Returns the field errors, or the new admin.
     *
     * @return array{ok: true, admin: AdminUser}|array{ok: false, errors: array<string, string>}
     */
    public static function create(array $input, string $actorId, ?string $ip = null): array
    {
        $data = [
            'name' => trim((string) ($input['name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($input['email'] ?? ''))),
            'password' => (string) ($input['password'] ?? ''),
            'password_confirmation' => (string) ($input['password_confirmation'] ?? ''),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'max:254', 'email'],
            'password' => self::PASSWORD_RULES,
        ], self::MESSAGES);
        $errors = array_map(fn ($messages) => $messages[0], $validator->errors()->toArray());
        if (! isset($errors['email']) && AdminUser::where('email', $data['email'])->exists()) {
            $errors['email'] = "Ya existe un usuario con el email {$data['email']}.";
        }
        $access = AdminAccess::fromInput($input);
        if (! $access['ok']) {
            $errors += $access['errors'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $admin = AdminUser::create([
            'email' => $data['email'],
            'name' => $data['name'],
            'password_hash' => Passwords::hash($data['password']),
            'access' => $access['access'],
            'permissions' => $access['permissions'],
        ]);
        Audit::log($actorId, 'admin.creado', 'admin', $admin->id, [
            'email' => $admin->email,
            'origen' => 'panel',
            'acceso' => $admin->access,
            'permisos' => $admin->permissions,
        ], $ip);

        return ['ok' => true, 'admin' => $admin];
    }

    /**
     * Sets a new password for another admin and closes their sessions.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, string>}
     */
    public static function resetPassword(AdminUser $target, array $input, string $actorId, ?string $ip = null): array
    {
        $errors = self::passwordErrors($input);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $target->update(['password_hash' => Passwords::hash((string) $input['password'])]);
        AdminSessions::invalidateAll($target->id);
        Audit::log($actorId, 'admin.password_cambiada', 'admin', $target->id, ['origen' => 'panel'], $ip);

        return ['ok' => true];
    }

    /**
     * Changes another admin's access level and permissions. It applies from their next request.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, string>}
     */
    public static function updateAccess(AdminUser $target, array $input, string $actorId, ?string $ip = null): array
    {
        $access = AdminAccess::fromInput($input);
        if (! $access['ok']) {
            return $access;
        }

        $target->update(['access' => $access['access'], 'permissions' => $access['permissions']]);
        Audit::log($actorId, 'admin.acceso_cambiado', 'admin', $target->id, [
            'email' => $target->email,
            'acceso' => $target->access,
            'permisos' => $target->permissions,
        ], $ip);

        return ['ok' => true];
    }

    /** Deactivates (closing their sessions) or reactivates another admin. */
    public static function setActive(AdminUser $target, bool $active, string $actorId, ?string $ip = null): void
    {
        $target->update(['disabled_at' => $active ? null : Clock::nowMs()]);
        if (! $active) {
            AdminSessions::invalidateAll($target->id);
        }
        Audit::log($actorId, $active ? 'admin.reactivado' : 'admin.desactivado', 'admin', $target->id, ['email' => $target->email], $ip);
    }

    /**
     * The logged-in admin changes their own password. The current password is checked with the
     * same rate limit as the login; the other sessions are closed and `$token`'s one stays.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, string>}
     */
    public static function changeOwnPassword(AdminUser $admin, array $input, string $token, string $ip): array
    {
        $current = (string) ($input['current_password'] ?? '');
        $keys = AuthRateLimiter::limitKeys(self::PASSWORD_SCOPE, $ip, $admin->email);
        $blocked = AuthRateLimiter::lockedUntil($keys);
        if ($blocked) {
            return ['ok' => false, 'errors' => ['current_password' => AuthRateLimiter::lockMessage($blocked)]];
        }
        if ($current === '' || mb_strlen($current) > 200 || ! Passwords::verify($admin->password_hash, $current)) {
            $lockedNow = AuthRateLimiter::recordFailure($keys);

            return ['ok' => false, 'errors' => ['current_password' => $lockedNow
                ? AuthRateLimiter::lockMessage($lockedNow)
                : 'La contraseña actual no es correcta.']];
        }
        AuthRateLimiter::clearFailures([$keys[1]['key']]);

        $errors = self::passwordErrors($input);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $admin->update(['password_hash' => Passwords::hash((string) $input['password'])]);
        AdminSessions::invalidateOthers($admin->id, $token);
        Audit::log($admin->id, 'admin.password_cambiada', 'admin', $admin->id, ['origen' => 'mi cuenta'], $ip);

        return ['ok' => true];
    }

    /** @return array<string, string> */
    private static function passwordErrors(array $input): array
    {
        $validator = Validator::make([
            'password' => (string) ($input['password'] ?? ''),
            'password_confirmation' => (string) ($input['password_confirmation'] ?? ''),
        ], ['password' => self::PASSWORD_RULES], self::MESSAGES);

        return array_map(fn ($messages) => $messages[0], $validator->errors()->toArray());
    }
}
