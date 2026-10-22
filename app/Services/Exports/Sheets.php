<?php

namespace App\Services\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Shared Excel pieces of the exports (port of the helpers in src/lib/server/export/excel.ts). */
final class Sheets
{
    public const PCT = '0%';

    public const HEADER_FILL = 'FFE2E8F0';

    public const CREATOR = 'Plataforma de Assessments';

    /** Avoids text that starts with =, +, - or @ being read as a formula (CSV/Excel injection). */
    public static function safeText(?string $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'{$v}" : $v;
    }

    /** Empty workbook (no default sheet) with the creator set. */
    public static function workbook(): Spreadsheet
    {
        $wb = new Spreadsheet;
        $wb->getProperties()->setCreator(self::CREATOR)->setLastModifiedBy(self::CREATOR);
        $wb->removeSheetByIndex(0);

        return $wb;
    }

    /** .xlsx bytes. */
    public static function toBytes(Spreadsheet $wb): string
    {
        $wb->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        try {
            (new Xlsx($wb))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $wb->disconnectWorksheets();
        }
    }
}
