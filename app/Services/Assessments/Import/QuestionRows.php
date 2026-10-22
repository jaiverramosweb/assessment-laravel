<?php

namespace App\Services\Assessments\Import;

use App\Services\Assessments\QuestionValidator;
use App\Services\Import\Table;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Questions from Excel/CSV (port of src/lib/server/import/questions-import.ts).
 * Template format:
 *   tipo | enunciado | obligatoria | opcion_1 … opcion_6 | correcta
 * - tipo: "seleccion" or "abierta" (also "opcion_unica", "multiple", "texto")
 * - obligatoria: si / no (default si)
 * - correcta: number of the correct option (1–6) or letter (A–F)
 */
final class QuestionRows
{
    public const TEMPLATE_HEADERS = [
        'tipo', 'enunciado', 'obligatoria',
        'opcion_1', 'opcion_2', 'opcion_3', 'opcion_4', 'opcion_5', 'opcion_6',
        'correcta',
    ];

    private const TYPE_ALIASES = [
        'seleccion' => 'opcion_unica',
        'seleccion_multiple' => 'opcion_unica',
        'seleccion_unica' => 'opcion_unica',
        'multiple' => 'opcion_unica',
        'opcion_unica' => 'opcion_unica',
        'opcion' => 'opcion_unica',
        'abierta' => 'abierta',
        'texto' => 'abierta',
        'respuesta_abierta' => 'abierta',
    ];

    private const YES = ['si', 's', 'true', '1', 'x', 'yes'];

    private const NO = ['no', 'n', 'false', '0'];

    /** First data row is row 2 (row 1 = headers), as the user sees it in Excel. */
    private const ROW_OFFSET = 2;

    /**
     * Turns the file rows into validated questions. With any error nothing is imported.
     *
     * @return array{ok: true, questions: array}|array{ok: false, errors: string[]}
     */
    public static function parse(Table $table): array
    {
        if (! in_array('tipo', $table->headers, true)
            || (! in_array('enunciado', $table->headers, true) && ! in_array('pregunta', $table->headers, true))) {
            return ['ok' => false, 'errors' => ['El archivo debe tener las columnas "tipo" y "enunciado". Descargue la plantilla.']];
        }

        $errors = [];
        $candidates = [];
        foreach ($table->rows as $i => $row) {
            $rowNum = $i + self::ROW_OFFSET;
            $typeCell = $table->cell($row, 'tipo');
            $type = self::TYPE_ALIASES[Table::normalizeHeader($typeCell)] ?? null;
            if (! $type) {
                $errors[] = "Fila {$rowNum}: tipo \"{$typeCell}\" no reconocido (use \"seleccion\" o \"abierta\").";
                $candidates[] = null;

                continue;
            }

            $reqRaw = Table::normalizeHeader($table->cell($row, 'obligatoria', 'requerida'));
            $required = true;
            if (in_array($reqRaw, self::NO, true)) {
                $required = false;
            } elseif ($reqRaw !== '' && ! in_array($reqRaw, self::YES, true)) {
                $errors[] = "Fila {$rowNum}: \"obligatoria\" debe ser \"si\" o \"no\".";
            }

            $prompt = $table->cell($row, 'enunciado', 'pregunta');
            if ($type === 'abierta') {
                $candidates[] = ['type' => $type, 'prompt' => $prompt, 'required' => $required];

                continue;
            }

            $texts = [];
            for ($k = 1; $k <= QuestionValidator::MAX_OPTIONS; $k++) {
                $texts[] = $table->cell($row, "opcion_{$k}");
            }
            // Empty options at the end are ignored; those in between are an error.
            $lastFilled = -1;
            foreach ($texts as $k => $t) {
                if ($t !== '') {
                    $lastFilled = $k;
                }
            }
            $used = array_slice($texts, 0, $lastFilled + 1);
            if (in_array('', $used, true)) {
                $errors[] = "Fila {$rowNum}: hay opciones vacías entre opciones con texto.";
            }

            $correctRaw = mb_strtoupper($table->cell($row, 'correcta', 'respuesta_correcta'));
            $correct = self::jsNumber($correctRaw);
            if (preg_match('/^[A-F]$/', $correctRaw)) {
                $correct = (float) (ord($correctRaw) - 64);
            }
            if ($correct === null || floor($correct) !== $correct || $correct < 1 || $correct > count($used)) {
                $max = max(count($used), 1);
                $errors[] = "Fila {$rowNum}: \"correcta\" debe ser el número de una opción con texto (1 a {$max}).";
            }

            $candidates[] = [
                'type' => $type,
                'prompt' => $prompt,
                'required' => $required,
                'options' => array_map(fn ($text, $k) => ['text' => $text, 'isCorrect' => $k + 1 === (int) $correct && $correct !== null], $used, array_keys($used)),
            ];
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $parsed = QuestionValidator::validateList($candidates, 'Fila', self::ROW_OFFSET);

        return $parsed['ok'] ? ['ok' => true, 'questions' => $parsed['questions']] : $parsed;
    }

    /** Downloadable Excel template, with examples and an instructions sheet. Returns the .xlsx bytes. */
    public static function buildTemplate(): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Plataforma de Assessments');

        $ws = $book->getActiveSheet();
        $ws->setTitle('Preguntas');
        foreach (self::TEMPLATE_HEADERS as $i => $h) {
            $col = chr(ord('A') + $i);
            $ws->setCellValue("{$col}1", $h);
            $ws->getColumnDimension($col)->setWidth($h === 'enunciado' ? 60 : (str_starts_with($h, 'opcion') ? 28 : 14));
        }
        $ws->getStyle('A1:J1')->getFont()->setBold(true);
        $ws->freezePane('A2');
        $ws->fromArray([
            'seleccion',
            '¿Cuál es el principal objetivo de la política de seguridad de la información?',
            'si',
            'Proteger la confidencialidad, integridad y disponibilidad',
            'Reducir costos de tecnología',
            'Cumplir un requisito administrativo',
            null, null, null,
            1,
        ], null, 'A2');
        $ws->fromArray(['abierta', 'Describa una situación reciente en la que aplicó este concepto.', 'no'], null, 'A3');

        $help = $book->createSheet();
        $help->setTitle('Instrucciones');
        $help->getColumnDimension('A')->setWidth(18);
        $help->getColumnDimension('B')->setWidth(90);
        $help->fromArray([
            ['Columna', 'Cómo llenarla'],
            ['tipo', '"seleccion" (una sola respuesta correcta) o "abierta" (texto libre).'],
            ['enunciado', 'Texto de la pregunta (máximo 2.000 caracteres).'],
            ['obligatoria', '"si" o "no". Si se deja vacía, la pregunta es obligatoria.'],
            ['opcion_1 … opcion_6', 'Solo para "seleccion": entre 2 y 6 opciones, sin dejar huecos.'],
            ['correcta', 'Solo para "seleccion": número de la opción correcta (1 a 6) o letra (A a F).'],
            [null, null],
            ['Notas', 'La primera hoja es la que se importa. Puede guardar como .xlsx o .csv.'],
            [null, 'Si alguna fila tiene errores, no se importa nada y se muestra el detalle por fila.'],
        ], null, 'A1');
        $help->getStyle('A1:B1')->getFont()->setBold(true);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }

    /** JavaScript Number(): '' → 0, invalid → null (NaN). */
    private static function jsNumber(string $v): ?float
    {
        if ($v === '') {
            return 0.0;
        }

        return is_numeric($v) ? (float) $v : null;
    }
}
