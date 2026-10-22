<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Column that orders rows by insertion, for tables whose rows can share a millisecond
 * (ai_analyses, email_log): SQLite's implicit rowid, or the `seq` column on MySQL/MariaDB.
 */
final class InsertOrder
{
    public static function column(?string $table = null): string
    {
        $column = DB::connection()->getDriverName() === 'sqlite' ? 'rowid' : 'seq';

        return $table ? "{$table}.{$column}" : $column;
    }
}
