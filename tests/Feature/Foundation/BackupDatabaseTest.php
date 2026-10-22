<?php

namespace Tests\Feature\Foundation;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Artisan;
use PDO;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    private string $db;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'respaldo-'.bin2hex(random_bytes(4));
        mkdir($base);
        $this->db = $base.DIRECTORY_SEPARATOR.'app.sqlite';
        $this->dir = $base.DIRECTORY_SEPARATOR.'backups';
        touch($this->db);
        config(['database.connections.sqlite.database' => $this->db]);
        app('db')->purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_backup_is_a_consistent_copy_and_old_ones_are_pruned(): void
    {
        $this->createAdmin('ana@empresa.com');
        foreach (['app-20200101-000000.db', 'app-20200102-000000.db', 'otro.db'] as $f) {
            @mkdir($this->dir);
            file_put_contents($this->dir.DIRECTORY_SEPARATOR.$f, 'x');
        }

        $exit = Artisan::call('db:backup', ['--dir' => $this->dir, '--keep' => 2]);

        $this->assertSame(0, $exit);
        $backups = glob($this->dir.DIRECTORY_SEPARATOR.'app-*.db');
        $this->assertCount(2, $backups);
        $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.'otro.db');
        $this->assertStringContainsString('Se eliminaron 1 respaldos antiguos.', Artisan::output());

        $newest = max($backups);
        $copy = new PDO('sqlite:'.$newest);
        $this->assertSame('ana@empresa.com', $copy->query('select email from admin_users')->fetchColumn());
        $this->assertSame(1, AdminUser::count());
    }
}
