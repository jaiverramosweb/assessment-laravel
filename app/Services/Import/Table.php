<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;
use ZipArchive;

/**
 * Reads tabular files (Excel .xlsx or CSV) as rows of text.
 * Used to import questions and participants.
 */
final class Table
{
    public const MAX_IMPORT_BYTES = 2 * 1024 * 1024;

    /** An .xlsx is a ZIP: cap on its uncompressed content (protects against "zip bombs"). */
    public const MAX_XLSX_UNZIPPED_BYTES = 20 * 1024 * 1024;

    public const MAX_IMPORT_ROWS = 2000;

    public const MAX_IMPORT_COLUMNS = 50;

    /** @param string[] $headers @param string[][] $rows */
    public function __construct(public readonly array $headers, public readonly array $rows) {}

    /** Normalizes a header: lowercase, no accents, spaces → "_". "Opción 1" → "opcion_1". */
    public static function normalizeHeader(string $h): string
    {
        $h = \Normalizer::normalize($h, \Normalizer::FORM_D);
        $h = preg_replace('/\p{Mn}/u', '', $h);
        $h = mb_strtolower(trim($h));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);

        return trim($h, '_');
    }

    /**
     * CSV parser (RFC 4180): quotes, escaped quotes ("") and line breaks inside fields.
     * Detects the separator: Spanish Excel usually exports with ";".
     *
     * @return string[][]
     */
    public static function parseCsv(string $input): array
    {
        $text = str_starts_with($input, "\u{FEFF}") ? substr($input, 3) : $input;
        $firstLine = preg_split('/\r?\n/', $text, 2)[0];
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inQuotes) {
                if ($ch === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $ch;
                }
            } elseif ($ch === '"' && $field === '') {
                $inQuotes = true;
            } elseif ($ch === $delimiter) {
                $row[] = $field;
                $field = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $row[] = $field;
                $rows[] = $row;
                $row = [];
                $field = '';
            } else {
                $field .= $ch;
            }
        }
        if ($field !== '' || $row !== []) {
            $row[] = $field;
            $rows[] = $row;
        }

        return $rows;
    }

    /** Reads the uploaded file and returns normalized headers + non-empty rows. */
    public static function read(?UploadedFile $file): self
    {
        if (! $file || $file->getSize() === 0) {
            throw new ImportFileError('Seleccione un archivo.');
        }
        if ($file->getSize() > self::MAX_IMPORT_BYTES) {
            throw new ImportFileError('El archivo supera el máximo de 2 MB.');
        }

        $name = mb_strtolower($file->getClientOriginalName());
        if (str_ends_with($name, '.xlsx')) {
            $raw = self::readXlsx($file->getRealPath());
        } elseif (str_ends_with($name, '.csv')) {
            $raw = self::parseCsv((string) file_get_contents($file->getRealPath()));
        } else {
            throw new ImportFileError('Formato no soportado. Use un archivo .xlsx o .csv.');
        }

        $nonEmpty = array_values(array_filter(
            array_map(fn ($r) => array_map(fn ($c) => trim((string) $c), $r), $raw),
            fn ($r) => count(array_filter($r, fn ($c) => $c !== '')) > 0,
        ));
        if (count($nonEmpty) < 2) {
            throw new ImportFileError('El archivo no tiene filas de datos.');
        }
        if (count($nonEmpty) - 1 > self::MAX_IMPORT_ROWS) {
            throw new ImportFileError('El archivo supera el máximo de '.self::MAX_IMPORT_ROWS.' filas.');
        }

        $headerRow = array_shift($nonEmpty);

        return new self(array_map([self::class, 'normalizeHeader'], $headerRow), $nonEmpty);
    }

    /** Cell by normalized column name; the first existing name wins. */
    public function cell(array $row, string ...$names): string
    {
        foreach ($names as $n) {
            $i = array_search($n, $this->headers, true);
            if ($i !== false) {
                return trim((string) ($row[$i] ?? ''));
            }
        }

        return '';
    }

    /** @return string[][] */
    private static function readXlsx(string $path): array
    {
        $safe = self::rezipWithinLimit($path, self::MAX_XLSX_UNZIPPED_BYTES);
        try {
            $reader = new Xlsx;
            $reader->setReadEmptyCells(false);
            $sheet = $reader->load($safe)->getSheet(0);
        } catch (Throwable) {
            throw new ImportFileError('No se pudo leer el archivo Excel. Verifique que sea un .xlsx válido.');
        } finally {
            @unlink($safe);
        }

        // Limits before walking: one cell in the last column/row would make the walk huge
        // even when the file is small.
        $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($width > self::MAX_IMPORT_COLUMNS) {
            throw new ImportFileError('El archivo tiene demasiadas columnas (máximo '.self::MAX_IMPORT_COLUMNS.').');
        }
        $height = $sheet->getHighestDataRow();
        if ($height > self::MAX_IMPORT_ROWS + 1) {
            throw new ImportFileError('El archivo supera el máximo de '.self::MAX_IMPORT_ROWS.' filas.');
        }

        $rows = [];
        for ($r = 1; $r <= $height; $r++) {
            $cells = [];
            for ($c = 1; $c <= $width; $c++) {
                $cell = $sheet->getCell([$c, $r]);
                // Formatted value resolves rich text, formulas, numbers and dates like Excel shows them.
                $cells[] = (string) $cell->getFormattedValue();
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * Decompresses the .xlsx with a cap and repackages it without compression. The spreadsheet
     * reader only gets that copy, never the original, so it cannot decompress more than `max`.
     * Actual bytes are counted while streaming (a forged declared size cannot overflow the cap).
     */
    private static function rezipWithinLimit(string $path, int $max): string
    {
        $invalid = 'No se pudo leer el archivo Excel. Verifique que sea un .xlsx válido.';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportFileError($invalid);
        }
        $out = new ZipArchive;
        $copy = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($out->open($copy, ZipArchive::OVERWRITE) !== true) {
            $zip->close();
            throw new ImportFileError($invalid);
        }

        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new ImportFileError($invalid);
                }
                $data = '';
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        break;
                    }
                    $total += strlen($chunk);
                    if ($total > $max) {
                        fclose($stream);
                        throw new ImportFileError('El archivo Excel es demasiado grande al descomprimirlo.');
                    }
                    $data .= $chunk;
                }
                fclose($stream);
                $out->addFromString($name, $data);
                $out->setCompressionName($name, ZipArchive::CM_STORE);
            }
            $out->close();
        } catch (Throwable $e) {
            @$out->close();
            @unlink($copy);
            throw $e instanceof ImportFileError ? $e : new ImportFileError($invalid);
        } finally {
            $zip->close();
        }

        return $copy;
    }
}
