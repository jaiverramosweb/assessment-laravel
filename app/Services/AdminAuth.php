<?php

namespace App\Services;

use App\Auth\AdminSessions;
use App\Auth\Passwords;
use App\Models\AdminUser;
use App\Security\Audit;
use App\Security\AuthRateLimiter;
use App\Support\Clock;
use RuntimeException;

final class AdminAuth
{
    public const LOGIN_SCOPE = 'admin-login';

    /**
     * Checks admin credentials applying rate limiting by IP and by email.
     * Does not tell "unknown email" from "wrong password", so accounts are not revealed.
     *
     * @return array{ok: true, admin: AdminUser}|array{ok: false, reason: 'invalid'}|array{ok: false, reason: 'locked', until: int}
     */
    public static function authenticate(string $email, string $password, string $ip, ?int $now = null): array
    {
        $now ??= Clock::nowMs();
        $keys = AuthRateLimiter::limitKeys(self::LOGIN_SCOPE, $ip, $email);

        $blocked = AuthRateLimiter::lockedUntil($keys, $now);
        if ($blocked) {
            return ['ok' => false, 'reason' => 'locked', 'until' => $blocked];
        }

        $user = AdminUser::where('email', $email)->first();
        // An Argon2 verification always runs so the response time is even.
        $valid = Passwords::verify($user?->password_hash ?? Passwords::dummyHash(), $password);

        if (! $user || ! $valid) {
            $lockedNow = AuthRateLimiter::recordFailure($keys, $now);
            Audit::log($user?->id, 'admin.login_fallido', metadata: ['email' => $email], ip: $ip);

            return $lockedNow
                ? ['ok' => false, 'reason' => 'locked', 'until' => $lockedNow]
                : ['ok' => false, 'reason' => 'invalid'];
        }

        // Success: the email counter is cleared (the IP one keeps its window).
        AuthRateLimiter::clearFailures([$keys[1]['key']]);
        $user->update(['last_login_at' => $now]);
        Audit::log($user->id, 'admin.login', ip: $ip);

        return ['ok' => true, 'admin' => $user];
    }

    /** Creates an admin. Input must already be validated. */
    public static function create(string $email, string $name, string $password): AdminUser
    {
        if (AdminUser::where('email', $email)->exists()) {
            throw new RuntimeException("Ya existe un administrador con el email {$email}");
        }
        $admin = AdminUser::create([
            'email' => $email,
            'name' => $name,
            'password_hash' => Passwords::hash($password),
        ]);
        Audit::log(null, 'admin.creado', 'admin', $admin->id, ['email' => $admin->email, 'origen' => 'terminal']);

        return $admin;
    }

    /** Changes an admin's password and closes all their open sessions. */
    public static function resetPassword(string $email, string $password): bool
    {
        $user = AdminUser::where('email', $email)->first();
        if (! $user) {
            return false;
        }
        $user->update(['password_hash' => Passwords::hash($password)]);
        AdminSessions::invalidateAll($user->id);
        Audit::log(null, 'admin.password_cambiada', 'admin', $user->id, ['origen' => 'terminal']);

        return true;
    }
}
