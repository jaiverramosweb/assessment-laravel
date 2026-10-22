<?php

namespace Tests\Feature\Foundation;

use App\Models\AdminUser;
use App\Services\Clients\ClientService;
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
            // Data here is committed (no test transaction): leave an empty database behind.
            Artisan::call('migrate:fresh', ['--force' => true]);
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

    public function test_a_mysql_dump_restores_every_row_and_byte(): void
    {
        if (! $this->mysql) {
            $this->markTestSkipped('The SQL dump is only used on MySQL/MariaDB.');
        }
        $admin = $this->createAdmin('ana@empresa.com');
        $client = ClientService::create('Café "Ñandú" \'n\' \\ co', $admin->id);
        $logo = "\x89PNG\r\n\x1a\n".implode('', array_map('chr', range(0, 255))).str_repeat("\x00", 300_000);
        ClientService::setLogo($client->id, $logo, $admin->id);
        $a = $this->createAssessment(['client_id' => $client->id, 'description' => "Línea 1\nLínea 2 😀"]);
        DB::table('email_log')->insert(['id' => 'log-1', 'participant_id' => $this->createParticipant($a)->id, 'status' => 'enviado', 'created_at' => 1]);
        $counts = fn () => collect(['admin_users', 'clients', 'assessments', 'participants', 'email_log', 'audit_log'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $before = $counts();

        $this->assertSame(0, Artisan::call('db:backup', ['--dir' => $this->dir]));
        $dump = glob($this->dir.DIRECTORY_SEPARATOR.'app-*.sql')[0];
        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::unprepared(file_get_contents($dump));

        $this->assertSame($before, $counts());
        $this->assertSame($logo, ClientService::readLogo($client->id)['data']);
        $this->assertSame('Café "Ñandú" \'n\' \\ co', DB::table('clients')->where('id', $client->id)->value('name'));
        $this->assertSame("Línea 1\nLínea 2 😀", DB::table('assessments')->where('id', $a->id)->value('description'));
        // The insertion order column survives, so new rows keep sorting after the restored ones.
        DB::table('email_log')->insert(['id' => 'log-2', 'participant_id' => DB::table('participants')->value('id'), 'status' => 'enviado', 'created_at' => 1]);
        $this->assertSame(['log-1', 'log-2'], DB::table('email_log')->orderBy('seq')->pluck('id')->all());
    }
}
