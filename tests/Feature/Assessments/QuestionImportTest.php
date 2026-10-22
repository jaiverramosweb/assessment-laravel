<?php

namespace Tests\Feature\Assessments;

use App\Services\Assessments\Import\QuestionRows;
use App\Services\Import\Table;
use Tests\Fixtures\Assessments\Builders;
use Tests\TestCase;

/** Port of the question part of src/lib/server/import/questions-import.spec.ts. */
class QuestionImportTest extends TestCase
{
    private function row(array $values): array
    {
        return array_map(fn ($h) => $values[$h] ?? '', QuestionRows::TEMPLATE_HEADERS);
    }

    private function table(array $rows): Table
    {
        return new Table(QuestionRows::TEMPLATE_HEADERS, $rows);
    }

    public function test_converts_valid_rows_number_or_letter_for_the_correct_one(): void
    {
        $r = QuestionRows::parse($this->table([
            $this->row(['tipo' => 'Selección', 'enunciado' => '¿2+2?', 'opcion_1' => '3', 'opcion_2' => '4', 'correcta' => '2']),
            $this->row(['tipo' => 'seleccion', 'enunciado' => '¿Color?', 'opcion_1' => 'Rojo', 'opcion_2' => 'Azul', 'correcta' => 'a', 'obligatoria' => 'no']),
            $this->row(['tipo' => 'abierta', 'enunciado' => 'Explique']),
        ]));
        $this->assertTrue($r['ok']);
        $this->assertCount(3, $r['questions']);
        $this->assertSame([
            'type' => 'opcion_unica',
            'prompt' => '¿2+2?',
            'required' => true,
            'options' => [['text' => '3', 'isCorrect' => false], ['text' => '4', 'isCorrect' => true]],
        ], $r['questions'][0]);
        $this->assertFalse($r['questions'][1]['required']);
        $this->assertSame([true, false], array_column($r['questions'][1]['options'], 'isCorrect'));
        $this->assertSame(['type' => 'abierta', 'prompt' => 'Explique', 'required' => true], $r['questions'][2]);
    }

    public function test_reports_errors_per_row_and_imports_nothing(): void
    {
        $r = QuestionRows::parse($this->table([
            $this->row(['tipo' => 'abierta', 'enunciado' => 'Bien']),
            $this->row(['tipo' => 'encuesta', 'enunciado' => 'x']),
            $this->row(['tipo' => 'seleccion', 'enunciado' => 'Una sola opción', 'opcion_1' => 'A', 'correcta' => '1']),
            $this->row(['tipo' => 'seleccion', 'enunciado' => 'Sin correcta', 'opcion_1' => 'A', 'opcion_2' => 'B', 'correcta' => '5']),
            $this->row(['tipo' => 'abierta', 'enunciado' => 'Otra', 'obligatoria' => 'quizás']),
            $this->row(['tipo' => 'seleccion', 'enunciado' => 'Hueco', 'opcion_1' => 'A', 'opcion_3' => 'C', 'correcta' => '1']),
        ]));
        $this->assertFalse($r['ok']);
        $this->assertContains('Fila 3: tipo "encuesta" no reconocido (use "seleccion" o "abierta").', $r['errors']);
        $this->assertContains('Fila 5: "correcta" debe ser el número de una opción con texto (1 a 2).', $r['errors']);
        $this->assertContains('Fila 6: "obligatoria" debe ser "si" o "no".', $r['errors']);
        $this->assertContains('Fila 7: hay opciones vacías entre opciones con texto.', $r['errors']);
    }

    public function test_validates_after_converting_minimum_two_options(): void
    {
        $r = QuestionRows::parse($this->table([
            $this->row(['tipo' => 'seleccion', 'enunciado' => 'Una sola', 'opcion_1' => 'A', 'correcta' => '1']),
        ]));
        $this->assertSame(['ok' => false, 'errors' => ['Fila 2: Debe tener al menos 2 opciones.']], $r);
    }

    public function test_requires_the_minimum_columns(): void
    {
        $r = QuestionRows::parse(new Table(['a', 'b'], [['1', '2']]));
        $this->assertSame(['El archivo debe tener las columnas "tipo" y "enunciado". Descargue la plantilla.'], $r['errors']);
    }

    public function test_the_downloadable_template_imports_as_is(): void
    {
        $table = Table::read(Builders::upload('plantilla.xlsx', QuestionRows::buildTemplate()));
        $r = QuestionRows::parse($table);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['opcion_unica', 'abierta'], array_column($r['questions'], 'type'));
        $this->assertTrue($r['questions'][0]['options'][0]['isCorrect']);
        $this->assertFalse($r['questions'][1]['required']);
    }

    public function test_template_has_the_instructions_sheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, QuestionRows::buildTemplate());
        $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path);
        @unlink($path);
        $this->assertSame(['Preguntas', 'Instrucciones'], $book->getSheetNames());
        $this->assertSame('tipo', $book->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('correcta', $book->getSheet(0)->getCell('J1')->getValue());
        $this->assertSame('Cómo llenarla', $book->getSheet(1)->getCell('B1')->getValue());
    }
}
