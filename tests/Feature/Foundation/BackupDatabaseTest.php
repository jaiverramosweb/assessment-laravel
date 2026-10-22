<?php

namespace Tests\Feature\Foundation;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    private string $dir;

    private bool $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'respaldo-'.bin2hex(random_bytes(4));
        mkdir($base);
        $this->dir = $base.DIRECTORY_SEPARATOR.'backups';
        $this->mysql = DB::connection()->getDriverName() !== 'sqlite';
        if (! $this->mysql) {
            // A file database (the default test database lives in memory).
            $db = $base.DIRECTORY_SEPARATOR.'app.sqlite';
            touch($db);
            config(['database.connections.sqlite.database' => $db]);
            app('db')->purge('sqlite');
        }
        // The backup runs outside any test transaction, so the data must be committed.
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->mysql) {
            AdminUser::query()->delete();
        }
        parent::tearDown();
    }

    public function test_backup_is_a_consistent_copy_and_old_ones_are_pruned(): void
    {
        $ext = $this->mysql ? 'sql' : 'db';
        $this->createAdmin('ana@empresa.com');
        foreach (["app-20200101-000000.{$ext}", 'app-20200102-000000.db', 'otro.db'] as $f) {
            @mkdir($this->dir);
            file_put_contents($this->dir.DIRECTORY_SEPARATOR.$f, 'x');
        }

        $exit = Artisan::call('db:backup', ['--dir' => $this->dir, '--keep' => 2]);

        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $backups = glob($this->dir.DIRECTORY_SEPARATOR.'app-*');
        $this->assertCount(2, $backups);
        $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.'otro.db');
        $this->assertStringContainsString('Se eliminaron 1 respaldos antiguos.', $output);

        $newest = max($backups);
        $this->assertStringEndsWith(".{$ext}", $newest);
        if ($this->mysql) {
            $dump = file_get_contents($newest);
            $this->assertStringContainsString('CREATE TABLE `admin_users`', $dump);
            $this->assertStringContainsString('ana@empresa.com', $dump);
        } else {
            $copy = new PDO('sqlite:'.$newest);
            $this->assertSame('ana@empresa.com', $copy->query('select email from admin_users')->fetchColumn());
        }
        $this->assertSame(1, AdminUser::count());
    }

    public function test_a_failed_dump_reports_the_error_and_leaves_no_file(): void
    {
        if (! $this->mysql) {
            $this->markTestSkipped('Only MySQL/MariaDB backups use mysqldump.');
        }
        config(['assessments.mysqldump_path' => 'mysqldump-que-no-existe']);

        $exit = Artisan::call('db:backup', ['--dir' => $this->dir]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No se pudo crear el respaldo', Artisan::output());
        $this->assertSame([], glob($this->dir.DIRECTORY_SEPARATOR.'app-*'));
    }
}
