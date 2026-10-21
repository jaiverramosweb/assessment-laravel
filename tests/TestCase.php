<?php

namespace Tests;

use App\Auth\AdminSessions;
use App\Auth\Passwords;
use App\Models\AdminUser;
use App\Models\Assessment;
use App\Models\Participant;
use App\Security\SecretBox;
use App\Support\Clock;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

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

    /** Creates an assessment (draft, open for 30 days from now unless overridden). */
    protected function createAssessment(array $attributes = []): Assessment
    {
        $now = Clock::nowMs();

        return Assessment::create([
            'slug' => Str::lower(Str::random(12)),
            'title' => 'Assessment de prueba',
            'starts_at' => $now - 3600 * 1000,
            'ends_at' => $now + 30 * 24 * 3600 * 1000,
            ...$attributes,
        ]);
    }

    protected function createParticipant(Assessment $assessment, array $attributes = []): Participant
    {
        return Participant::create([
            'assessment_id' => $assessment->id,
            'name' => 'Camila Vargas',
            'email' => 'camila.'.Str::lower(Str::random(6)).'@ejemplo.test',
            ...$attributes,
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
