<?php

namespace Tests\Feature\Foundation;

use App\Services\Import\ImportFileError;
use App\Services\Import\Table;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

class ImportTableTest extends TestCase
{
    public function test_normalize_header(): void
    {
        $this->assertSame('opcion_1', Table::normalizeHeader(' Opción 1 '));
        $this->assertSame('correo_electronico', Table::normalizeHeader('Correo electrónico'));
    }

    public function test_csv_detects_semicolon_and_handles_quotes_and_bom(): void
    {
        $csv = "\u{FEFF}nombre;email\r\n\"Pérez; Ana\";ana@x.com\n\"Dice \"\"hola\"\"\nsegunda\";b@x.com";

        $this->assertSame([
            ['nombre', 'email'],
            ['Pérez; Ana', 'ana@x.com'],
            ["Dice \"hola\"\nsegunda", 'b@x.com'],
        ], Table::parseCsv($csv));
    }

    public function test_reads_csv_upload_with_normalized_headers_and_skips_empty_rows(): void
    {
        $file = UploadedFile::fake()->createWithContent('p.csv', "Nombre,Correo Electrónico\nAna,ana@x.com\n,\n");

        $table = Table::read($file);

        $this->assertSame(['nombre', 'correo_electronico'], $table->headers);
        $this->assertSame([['Ana', 'ana@x.com']], $table->rows);
        $this->assertSame('ana@x.com', $table->cell($table->rows[0], 'email', 'correo_electronico'));
        $this->assertSame('', $table->cell($table->rows[0], 'cargo'));
    }

    public function test_reads_xlsx_formatted_values(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Nombre', 'Número'], ['Ana', 42]]);
        $path = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        (new Xlsx($book))->save($path);

        $table = Table::read(new UploadedFile($path, 'datos.xlsx', null, null, true));

        $this->assertSame(['nombre', 'numero'], $table->headers);
        $this->assertSame([['Ana', '42']], $table->rows);
    }

    public function test_rejects_bad_files(): void
    {
        $cases = [
            [null, 'Seleccione un archivo.'],
            [UploadedFile::fake()->createWithContent('a.txt', "a\nb"), 'Formato no soportado. Use un archivo .xlsx o .csv.'],
            [UploadedFile::fake()->createWithContent('a.csv', 'solo,encabezados'), 'El archivo no tiene filas de datos.'],
            [UploadedFile::fake()->createWithContent('a.xlsx', 'no es zip'), 'No se pudo leer el archivo Excel. Verifique que sea un .xlsx válido.'],
            [UploadedFile::fake()->create('a.csv', 3000), 'El archivo supera el máximo de 2 MB.'],
        ];
        foreach ($cases as [$file, $message]) {
            try {
                Table::read($file);
                $this->fail("Expected: {$message}");
            } catch (ImportFileError $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_rejects_too_many_rows(): void
    {
        $csv = "nombre\n".str_repeat("x\n", Table::MAX_IMPORT_ROWS + 1);

        $this->expectExceptionMessage('El archivo supera el máximo de 2000 filas.');
        Table::read(UploadedFile::fake()->createWithContent('a.csv', $csv));
    }

    public function test_rejects_zip_bombs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'b').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/bomba.xml', str_repeat('0', Table::MAX_XLSX_UNZIPPED_BYTES + 1));
        $zip->close();
        $this->assertLessThan(Table::MAX_IMPORT_BYTES, filesize($path));

        $this->expectExceptionMessage('El archivo Excel es demasiado grande al descomprimirlo.');
        Table::read(new UploadedFile($path, 'bomba.xlsx', null, null, true));
    }
}
