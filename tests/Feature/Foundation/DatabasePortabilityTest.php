<?php

namespace Tests\Feature\Foundation;

use App\Models\Assessment;
use App\Security\AuthRateLimiter;
use App\Security\SecretBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Limits that SQLite never enforces but MySQL/MariaDB do (column sizes, missing defaults).
 * Run the suite on MySQL with DB_CONNECTION=mysql (see README).
 */
class DatabasePortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_with_the_longest_valid_email_are_counted(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 185).'.com'; // 254 characters
        $keys = AuthRateLimiter::participantLimitKeys('acceso:'.str_repeat('c', 36), '2001:db8::ffff:ffff:ffff:ffff', $email);

        for ($i = 0; $i < AuthRateLimiter::EMAIL_POLICY['maxFailures']; $i++) {
            $until = AuthRateLimiter::recordFailure($keys);
        }

        $this->assertNotNull($until);
        $this->assertSame($until, AuthRateLimiter::lockedUntil($keys));
    }

    public function test_on_mysql_the_encryption_key_file_lives_in_the_database_folder(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('The SQLite key file lives next to the database file.');
        }
        $file = database_path(SecretBox::KEY_FILE);
        $existed = is_file($file);
        config(['assessments.access_password_key' => null]);
        SecretBox::reset();
        $cwd = getcwd();
        chdir(sys_get_temp_dir());

        try {
            $payload = SecretBox::encrypt('Clave-de-acceso-1');

            $this->assertFileExists($file);
            SecretBox::reset();
            $this->assertSame('Clave-de-acceso-1', SecretBox::decrypt($payload));
        } finally {
            chdir($cwd);
            SecretBox::reset();
            if (! $existed) {
                @unlink($file);
            }
        }
    }

    public function test_a_new_assessment_gets_empty_texts_without_database_defaults(): void
    {
        $a = $this->createAssessment();

        $fresh = Assessment::find($a->id);
        $this->assertSame('', $fresh->instructions);
        $this->assertSame('', $fresh->invite_subject);
        $this->assertSame('', $fresh->invite_body);
    }
}
