<?php

namespace App\Services\Exports;

use App\Services\Reports\Format;
use DateTimeImmutable;

/**
 * Report Excel with four sheets: Resumen, Por pregunta, Por participante and Respuestas abiertas
 * (port of src/lib/server/export/excel.ts). Percentages are stored as numbers with a percent
 * format (not as text) so they can be sorted and charted in Excel.
 */
final class ReportExcel
{
    private const EXPECTED_FILL = 'FFEEF1FE';

    private const DATE_FMT = 'yyyy-mm-dd hh:mm';

    /**
     * Excel has no time zones: it shows the date as is. It is converted to the wall-clock time of
     * the assessment time zone so it matches the screen.
     */
    private static function excelDate(?int $ms, string $timeZone): ?DateTimeImmutable
    {
        if (! $ms) {
            return null;
        }

        return new DateTimeImmutable(Format::local($ms, $timeZone)->format('Y-m-d H:i:00'));
    }

    public static function build(array $meta, array $report): string
    {
        $wb = Sheets::workbook();
        $s = $report['summary'];
        $t = [Sheets::class, 'safeText'];

        // ---- 1. Resumen --------------------------------------------------------
        $resumen = (new Sheet($wb, 'Resumen'))->widths([34, 70]);
        $resumen->bold('A'.$resumen->add([$t($meta['title'])]), 14);
        if ($meta['description']) {
            $resumen->add([$t($meta['description'])]);
        }
        $resumen->add();
        $info = [
            ['Periodo', $meta['period']],
            ['Zona horaria', $meta['timezone']],
            ['Participantes incluidos', $meta['filtersLabel']],
            ['Generado', $meta['generatedAt']],
            ['', ''],
            ['Participantes', $s['invited']],
            ['Entregas', $s['submitted']],
            ['En progreso (no incluidos)', $s['inProgress']],
            ['Tasa de participación', $s['participationRate'], Sheets::PCT],
            ['Alineación promedio', $s['averageAlignment'], Sheets::PCT],
        ];
        foreach ($info as $item) {
            [$label, $value] = $item;
            $row = $resumen->add([$label, $value ?? '—']);
            $resumen->bold("A{$row}");
            if (isset($item[2]) && is_numeric($value)) {
                $resumen->numFmt("B{$row}", $item[2]);
            }
            $resumen->alignLeft("B{$row}");
        }
        foreach ([['Preguntas con mayor alineación', $s['mostAligned']], ['Preguntas con menor alineación', $s['leastAligned']]] as [$title, $list]) {
            if (! $list) {
                continue;
            }
            $resumen->add();
            $resumen->bold('A'.$resumen->add([$title]));
            foreach ($list as $c) {
                $row = $resumen->add(["{$c['number']}. ".$t($c['prompt']), $c['alignmentRate']]);
                $resumen->numFmt("B{$row}", Sheets::PCT);
                $resumen->alignLeft("B{$row}");
            }
        }
        $resumen->add();
        $row = $resumen->add([
            'Nota',
            'La alineación es el porcentaje de quienes respondieron una pregunta de selección que eligió la opción esperada. Solo se incluyen entregas finales.',
        ]);
        $resumen->wrapTop("A{$row}:B{$row}");

        // ---- 2. Por pregunta ---------------------------------------------------
        $porPregunta = (new Sheet($wb, 'Por pregunta'))->widths([6, 55, 45, 10, 10, 16]);
        $porPregunta->add(['N°', 'Pregunta', 'Opción', 'Esperada', 'Personas', '% de quienes respondieron']);
        $porPregunta->header(1, 6);
        $porPregunta->freeze('A2');
        foreach ($report['choice'] as $c) {
            foreach ($c['options'] as $o) {
                $row = $porPregunta->add([
                    $c['number'],
                    $t($c['prompt']),
                    $t($o['text']),
                    $o['isExpected'] ? 'Sí' : '',
                    $o['count'],
                    $c['respondents'] ? $o['rate'] : null,
                ]);
                $porPregunta->numFmt("F{$row}", Sheets::PCT);
                $porPregunta->wrapTop(Sheet::range($row, 6));
                if ($o['isExpected']) {
                    $porPregunta->fill(Sheet::range($row, 6), self::EXPECTED_FILL);
                }
            }
            $omitted = $c['omitted'] ? ", omitieron {$c['omitted']}" : '';
            $row = $porPregunta->add([
                $c['number'],
                '',
                "Alineación (respondieron {$c['respondents']}{$omitted})",
                '',
                $c['aligned'],
                $c['alignmentRate'],
            ]);
            $porPregunta->bold(Sheet::range($row, 6));
            $porPregunta->numFmt("F{$row}", Sheets::PCT);
            $porPregunta->add();
        }

        // ---- 3. Por participante -----------------------------------------------
        $choiceCols = array_map(fn ($c) => ['header' => "P{$c['number']}", 'id' => $c['id']], $report['choice']);
        $width = 7 + count($choiceCols);
        $porParticipante = (new Sheet($wb, 'Por participante'))
            ->widths([28, 30, 18, 18, 13, 18, 18, ...array_fill(0, count($choiceCols), 28)]);
        $porParticipante->add(['Nombre', 'Email', 'Área', 'Cargo', 'Estado', 'Inicio', 'Entrega', ...array_column($choiceCols, 'header')]);
        $porParticipante->header(1, $width);
        $porParticipante->freeze('B2');
        foreach ($report['participants'] as $p) {
            $answers = $report['answersByParticipant'][$p['id']] ?? [];
            $row = $porParticipante->add([
                $t($p['name']),
                $t($p['email']),
                $t($p['area']),
                $t($p['cargo']),
                ExportMeta::STATUS_TEXT[$p['status']],
                self::excelDate($p['startedAt'], $meta['timezone']),
                self::excelDate($p['submittedAt'], $meta['timezone']),
                ...array_map(fn ($c) => $t($answers[$c['id']] ?? null), $choiceCols),
            ]);
            $porParticipante->numFmt("F{$row}", self::DATE_FMT);
            $porParticipante->numFmt("G{$row}", self::DATE_FMT);
        }
        if ($report['participants']) {
            $porParticipante->ws->setAutoFilter(Sheet::range(1, $width));
        }
        // Reference of the questions at the bottom, to read the P1, P2… columns.
        if ($choiceCols) {
            $porParticipante->add();
            $porParticipante->bold('A'.$porParticipante->add(['Preguntas']));
            foreach ($report['choice'] as $c) {
                $porParticipante->add(["P{$c['number']}", $t($c['prompt'])]);
            }
        }

        // ---- 4. Respuestas abiertas --------------------------------------------
        $abiertas = (new Sheet($wb, 'Respuestas abiertas'))->widths([6, 40, 26, 28, 16, 16, 100]);
        $abiertas->add(['N°', 'Pregunta', 'Nombre', 'Email', 'Área', 'Cargo', 'Respuesta']);
        $abiertas->header(1, 7);
        $abiertas->freeze('A2');
        foreach ($report['open'] as $q) {
            foreach ($q['responses'] as $r) {
                $row = $abiertas->add([
                    $q['number'],
                    $t($q['prompt']),
                    $t($r['participantName']),
                    $t($r['email']),
                    $t($r['area']),
                    $t($r['cargo']),
                    $t($r['text']),
                ]);
                $abiertas->wrapTop(Sheet::range($row, 7));
            }
        }
        if ($abiertas->rowCount() > 1) {
            $abiertas->ws->setAutoFilter('A1:G1');
        }

        return Sheets::toBytes($wb);
    }
}
