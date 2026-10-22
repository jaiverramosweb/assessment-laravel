<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hot backup of the SQLite database (safe while the app is in use).
 * `VACUUM INTO` writes a consistent snapshot, including what is still in the WAL.
 *
 *   php artisan db:backup                         → storage/backups/app-YYYYMMDD-HHMMSS.db (UTC)
 *   php artisan db:backup --dir=/home/u/respaldos → another folder
 *   php artisan db:backup --keep=14               → keeps only the 14 most recent backups
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--dir= : Carpeta de destino} {--keep= : Cantidad de respaldos a conservar}';

    protected $description = 'Respaldo en caliente de la base de datos SQLite';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: storage_path('backups');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("No se pudo crear la carpeta {$dir}");

            return self::FAILURE;
        }

        $dest = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'app-'.gmdate('Ymd-His').'.db';
        if (file_exists($dest)) {
            $this->error("Ya existe {$dest}. Intente de nuevo en un segundo.");

            return self::FAILURE;
        }
        DB::statement('VACUUM INTO ?', [$dest]);
        $this->info(sprintf('Respaldo creado: %s (%d KB)', $dest, round(filesize($dest) / 1024)));

        $keep = $this->option('keep');
        if ($keep !== null && ctype_digit((string) $keep) && (int) $keep > 0) {
            $files = array_values(array_filter(
                scandir($dir) ?: [],
                fn ($f) => preg_match('/^app-\d{8}-\d{6}\.db$/', $f) === 1,
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
}
