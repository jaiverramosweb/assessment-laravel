<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Hot backup of the database (safe while the app is in use).
 * - SQLite: `VACUUM INTO` writes a consistent snapshot, including what is still in the WAL.
 * - MySQL/MariaDB: `mysqldump --single-transaction` writes a consistent SQL dump. The
 *   password goes in a temporary options file, never on the command line.
 *
 *   php artisan db:backup                         → storage/backups/app-YYYYMMDD-HHMMSS.db|.sql (UTC)
 *   php artisan db:backup --dir=/home/u/respaldos → another folder
 *   php artisan db:backup --keep=14               → keeps only the 14 most recent backups
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--dir= : Carpeta de destino} {--keep= : Cantidad de respaldos a conservar}';

    protected $description = 'Respaldo en caliente de la base de datos (SQLite o MySQL/MariaDB)';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: storage_path('backups');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("No se pudo crear la carpeta {$dir}");

            return self::FAILURE;
        }

        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $dest = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'app-'.gmdate('Ymd-His').($sqlite ? '.db' : '.sql');
        if (file_exists($dest)) {
            $this->error("Ya existe {$dest}. Intente de nuevo en un segundo.");

            return self::FAILURE;
        }

        if ($sqlite) {
            DB::statement('VACUUM INTO ?', [$dest]);
        } elseif (($error = $this->dumpMysql($dest)) !== null) {
            @unlink($dest);
            $this->error("No se pudo crear el respaldo: {$error}");

            return self::FAILURE;
        }
        $this->info(sprintf('Respaldo creado: %s (%d KB)', $dest, round(filesize($dest) / 1024)));

        $keep = $this->option('keep');
        if ($keep !== null && ctype_digit((string) $keep) && (int) $keep > 0) {
            $files = array_values(array_filter(
                scandir($dir) ?: [],
                fn ($f) => preg_match('/^app-\d{8}-\d{6}\.(db|sql)$/', $f) === 1,
            ));
            rsort($files);
            $old = array_slice($files, (int) $keep);
            foreach ($old as $f) {
                unlink($dir.DIRECTORY_SEPARATOR.$f);
            }
            if ($old) {
                $this->info('Se eliminaron '.count($old).' respaldos antiguos.');
            }
        }

        return self::SUCCESS;
    }

    /** Returns null on success, or the error message. */
    private function dumpMysql(string $dest): ?string
    {
        $c = DB::connection()->getConfig();
        $options = tempnam(sys_get_temp_dir(), 'dump');
        chmod($options, 0600);
        file_put_contents($options, implode("\n", [
            '[client]',
            'user="'.addcslashes((string) $c['username'], '"\\').'"',
            'password="'.addcslashes((string) $c['password'], '"\\').'"',
            ...(! empty($c['unix_socket']) ? ['socket="'.$c['unix_socket'].'"'] : [
                'host="'.$c['host'].'"',
                'port='.(int) $c['port'],
            ]),
        ])."\n");

        $out = fopen($dest, 'wb');
        try {
            $process = new Process([
                (string) config('assessments.mysqldump_path'),
                '--defaults-extra-file='.$options,
                '--single-transaction',
                '--quick',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                (string) $c['database'],
            ]);
            $process->setTimeout(600);
            $process->run(function ($type, $buffer) use ($out) {
                if ($type === Process::OUT) {
                    fwrite($out, $buffer);
                }
            });

            return $process->isSuccessful() ? null : (trim($process->getErrorOutput()) ?: 'mysqldump terminó con error');
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            fclose($out);
            @unlink($options);
        }
    }
}
