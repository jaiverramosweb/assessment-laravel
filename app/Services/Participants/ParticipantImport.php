<?php

namespace App\Services\Participants;

use App\Services\Import\Table;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Participants from an Excel/CSV table.
 * Format: nombre | email | area | cargo | (any other column is kept as additional data).
 */
final class ParticipantImport
{
    private const NAME_COLS = ['nombre', 'nombre_completo', 'name'];

    private const EMAIL_COLS = ['email', 'correo', 'correo_electronico', 'e_mail'];

    private const AREA_COLS = ['area', 'departamento'];

    private const CARGO_COLS = ['cargo', 'puesto', 'rol'];

    /** @return array{ok: true, participants: list<array>}|array{ok: false, errors: list<string>} */
    public static function parse(Table $table): array
    {
        $has = fn (array $cols) => (bool) array_intersect($cols, $table->headers);
        if (! $has(self::NAME_COLS) || ! $has(self::EMAIL_COLS)) {
            return ['ok' => false, 'errors' => ['El archivo debe tener las columnas "nombre" y "email". Descargue la plantilla.']];
        }

        $known = [...self::NAME_COLS, ...self::EMAIL_COLS, ...self::AREA_COLS, ...self::CARGO_COLS];
        $extraCols = array_filter($table->headers, fn ($h) => $h !== '' && ! in_array($h, $known, true));

        $errors = [];
        $seen = [];
        $out = [];
        foreach ($table->rows as $i => $row) {
            $rowNum = $i + 2;
            $extra = [];
            foreach ($extraCols as $col => $header) {
                $value = trim((string) ($row[$col] ?? ''));
                if ($value !== '') {
                    $extra[$header] = $value;
                }
            }

            $parsed = ParticipantInput::validate([
                'name' => $table->cell($row, ...self::NAME_COLS),
                'email' => $table->cell($row, ...self::EMAIL_COLS),
                'area' => $table->cell($row, ...self::AREA_COLS),
                'cargo' => $table->cell($row, ...self::CARGO_COLS),
                'extra' => $extra ?: null,
            ]);
            if (! $parsed['ok']) {
                $errors[] = "Fila {$rowNum}: ".reset($parsed['errors']);

                continue;
            }
            $email = $parsed['data']['email'];
            if (isset($seen[$email])) {
                $errors[] = "Fila {$rowNum}: el email {$email} ya aparece en la fila {$seen[$email]}.";

                continue;
            }
            $seen[$email] = $rowNum;
            $out[] = $parsed['data'];
        }

        return $errors ? ['ok' => false, 'errors' => $errors] : ['ok' => true, 'participants' => $out];
    }

    /** Excel template to load participants (bytes of an .xlsx). */
    public static function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Participantes');
        $sheet->fromArray([
            ['nombre', 'email', 'area', 'cargo'],
            ['Ana Pérez', 'ana.perez@empresa.com', 'Tecnología', 'Analista'],
            ['Luis Gómez', 'luis.gomez@empresa.com', 'Finanzas', 'Coordinador'],
        ]);
        foreach (['A' => 32, 'B' => 34, 'C' => 22, 'D' => 22] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $help = $book->createSheet()->setTitle('Instrucciones');
        $help->fromArray([
            ['Columna', 'Cómo llenarla'],
            ['nombre', 'Obligatorio. Nombre completo.'],
            ['email', 'Obligatorio. Debe ser único en el archivo.'],
            ['area', 'Opcional. Se usa para filtrar los reportes.'],
            ['cargo', 'Opcional. Se usa para filtrar los reportes.'],
            ['Otras', 'Puede agregar más columnas (ej. "sede"): se guardan como datos adicionales.'],
            ['', ''],
            ['Notas', 'Si el email ya está cargado, se actualizan sus datos (no se duplica).'],
            ['', 'Si alguna fila tiene errores, no se carga nada y se muestra el detalle por fila.'],
        ]);
        $help->getColumnDimension('A')->setWidth(16);
        $help->getColumnDimension('B')->setWidth(90);
        $help->getStyle('A1:B1')->getFont()->setBold(true);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }
}
