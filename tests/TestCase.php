<?php

namespace Tests;

use App\Auth\AdminSessions;
use App\Auth\Passwords;
use App\Models\AdminUser;
use App\Security\SecretBox;
use App\Support\Clock;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Clock::freeze(null);
        SecretBox::reset();
    }

    /** Creates an admin with a known password. */
    protected function createAdmin(string $email = 'ana@empresa.com', string $password = 'clave-segura-123'): AdminUser
    {
        return AdminUser::create([
            'email' => $email,
            'name' => 'Ana Pérez',
            'password_hash' => Passwords::hash($password),
        ]);
    }

    /** Logs in as an admin by attaching a valid session cookie to the next requests. */
    protected function actingAsAdmin(?AdminUser $admin = null): AdminUser
    {
        $admin ??= $this->createAdmin();
        $session = AdminSessions::create($admin->id, 12);
        $this->withUnencryptedCookie(AdminSessions::COOKIE, $session['token']);

        return $admin;
    }
}
