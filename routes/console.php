<?php

use App\Auth\AdminSessions;
use App\Auth\ParticipantSessions;
use App\Security\AuthRateLimiter;
use App\Services\AdminAuth;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/*
 * Creates an admin (or changes their password) from the terminal.
 *   php artisan admin:create
 *   php artisan admin:create --reset --email=ana@empresa.com     (closes their sessions)
 *   ADMIN_PASSWORD=... php artisan admin:create --email=ana@empresa.com --name="Ana Pérez"
 */
Artisan::command('admin:create {--email=} {--name=} {--reset}', function () {
    $email = mb_strtolower(trim((string) ($this->option('email') ?? text('Email'))));
    $emailCheck = Validator::make(['email' => $email], ['email' => 'required|email|max:254'], [
        'email.required' => 'Escriba un email.',
        'email.email' => 'Escriba un email válido.',
        'email.max' => 'El email es demasiado largo.',
    ]);
    if ($emailCheck->fails()) {
        $this->error($emailCheck->errors()->first());

        return 1;
    }

    $askPassword = function () {
        if ($env = getenv('ADMIN_PASSWORD')) {
            return $env;
        }
        while (true) {
            $password = password('Contraseña (mínimo 10 caracteres)');
            if (mb_strlen($password) < 10) {
                $this->error('La contraseña debe tener al menos 10 caracteres.');

                continue;
            }
            if (mb_strlen($password) > 200) {
                $this->error('La contraseña es demasiado larga.');

                continue;
            }
            if (password('Repita la contraseña') !== $password) {
                $this->error('Las contraseñas no coinciden.');

                continue;
            }

            return $password;
        }
    };

    if ($this->option('reset')) {
        $password = $askPassword();
        if (mb_strlen($password) < 10 || mb_strlen($password) > 200) {
            $this->error('La contraseña debe tener entre 10 y 200 caracteres.');

            return 1;
        }
        if (! AdminAuth::resetPassword($email, $password)) {
            $this->error("No existe un administrador con el email {$email}");

            return 1;
        }
        $this->info("Contraseña actualizada para {$email}. Sus sesiones abiertas se cerraron.");

        return 0;
    }

    $name = trim((string) ($this->option('name') ?? text('Nombre')));
    $password = $askPassword();
    $check = Validator::make(['name' => $name, 'password' => $password], [
        'name' => 'required|string|min:2|max:100',
        'password' => 'required|string|min:10|max:200',
    ], [
        'name.required' => 'El nombre es muy corto.',
        'name.min' => 'El nombre es muy corto.',
        'name.max' => 'El nombre es muy largo.',
        'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
        'password.max' => 'La contraseña es demasiado larga.',
    ]);
    if ($check->fails()) {
        $this->error($check->errors()->first());

        return 1;
    }

    try {
        $admin = AdminAuth::create($email, $name, $password);
    } catch (RuntimeException $e) {
        $this->error($e->getMessage().'. Use --reset para cambiar su contraseña.');

        return 1;
    }
    $this->info("Administrador creado: {$admin->name} <{$admin->email}>");

    return 0;
})->purpose('Crea un administrador o cambia su contraseña');

/* Deletes expired sessions and attempt counters that no longer lock (older than a day). */
Artisan::command('app:maintenance', function () {
    AdminSessions::deleteExpired();
    ParticipantSessions::deleteExpired();
    AuthRateLimiter::purgeStale(24 * 3600 * 1000);
    $this->info('Mantenimiento completado.');
})->purpose('Limpia sesiones vencidas e intentos antiguos');

// cPanel runs `php artisan schedule:run` every minute (see README).
Schedule::command('app:maintenance')->hourly();
// Queued jobs (AI analysis) run from cron, where PHP has no web time limit.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')->everyMinute()->withoutOverlapping();
