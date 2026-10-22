<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Hot backup of the database (safe while the app is in use).
 * - SQLite: `VACUUM INTO` writes a consistent snapshot, including what is still in the WAL.
 * - MySQL/MariaDB: an SQL dump written by PHP itself inside one consistent-snapshot
 *   transaction (the host disables proc_open, so mysqldump cannot be run). Restore it with
 *   phpMyAdmin (Import) or `mysql base < archivo.sql`.
 *
 *   php artisan db:backup                         → storage/backups/app-YYYYMMDD-HHMMSS.db|.sql (UTC)
 *   php artisan db:backup --dir=/home/u/respaldos → another folder
 *   php artisan db:backup --keep=14               → keeps only the 14 most recent backups
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--dir= : Carpeta de destino} {--keep= : Cantidad de respaldos a conservar}';

    protected $description = 'Respaldo en caliente de la base de datos (SQLite o MySQL/MariaDB)';

    /** Rows per INSERT statement. */
    private const ROWS_PER_INSERT = 100;

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
        } else {
            try {
                $this->dumpMysql(DB::connection(), $dest);
            } catch (\Throwable $e) {
                @unlink($dest);
                $this->error("No se pudo crear el respaldo: {$e->getMessage()}");

                return self::FAILURE;
            }
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

    /** Writes every table (structure and rows) as SQL, from one consistent snapshot. */
    private function dumpMysql(Connection $db, string $dest): void
    {
        $pdo = $db->getPdo();
        $out = fopen($dest, 'wb');
        if (! $out) {
            throw new \RuntimeException("No se pudo escribir {$dest}");
        }

        try {
            $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            fwrite($out, '-- Respaldo de '.$db->getDatabaseName().' ('.gmdate('Y-m-d H:i:s')." UTC)\n");
            fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");

            $tables = array_map(
                fn ($row) => array_values((array) $row)[0],
                $db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
            );
            foreach ($tables as $table) {
                $quoted = '`'.str_replace('`', '``', $table).'`';
                $create = array_values((array) $db->selectOne("SHOW CREATE TABLE {$quoted}"))[1];
                fwrite($out, "\nDROP TABLE IF EXISTS {$quoted};\n{$create};\n");

                $binary = [];
                foreach ($db->select("SHOW COLUMNS FROM {$quoted}") as $col) {
                    if (preg_match('/blob|binary/i', $col->Type)) {
                        $binary[$col->Field] = true;
                    }
                }

                $batch = [];
                foreach ($db->cursor("SELECT * FROM {$quoted}") as $row) {
                    $values = [];
                    foreach ((array) $row as $field => $value) {
                        $values[] = match (true) {
                            $value === null => 'NULL',
                            isset($binary[$field]) => $value === '' ? "''" : '0x'.bin2hex($value),
                            default => $pdo->quote((string) $value),
                        };
                    }
                    $batch[] = '('.implode(',', $values).')';
                    if (count($batch) === self::ROWS_PER_INSERT) {
                        fwrite($out, "INSERT INTO {$quoted} VALUES ".implode(",\n", $batch).";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    fwrite($out, "INSERT INTO {$quoted} VALUES ".implode(",\n", $batch).";\n");
                }
            }

            fwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            $db->unprepared('COMMIT');
            fclose($out);
        }
    }
}
