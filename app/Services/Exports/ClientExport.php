<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Services\Reports\Format;
use App\Support\Clock;

/**
 * Exports of a client's general report (Excel and PDF) with its logo and colors
 * (port of src/lib/server/export/client-export.ts; view: resources/views/pdf/client.blade.php).
 */
final class ClientExport
{
    /** @return array{name: string, generatedAt: string, logoDataUrl: ?string, themePrimary: ?string, themeAccent: ?string} */
    public static function meta(Client $client, ?int $nowMs = null): array
    {
        return [
            'name' => $client->name,
            'generatedAt' => Format::longDateTime($nowMs ?? Clock::nowMs(), Format::DEFAULT_TIMEZONE),
            ...ExportMeta::brand($client->id),
        ];
    }

    public static function period(array $a): string
    {
        return Format::shortDate($a['startsAt'], $a['timezone']).' – '.Format::shortDate($a['endsAt'], $a['timezone']);
    }

    // ---- Excel ---------------------------------------------------------------------

    public static function excel(array $meta, array $report): string
    {
        $wb = Sheets::workbook();
        $t = [Sheets::class, 'safeText'];
        $s = $report['summary'];
        $pct = fn (?float $v) => $v ?? '—';

        $resumen = (new Sheet($wb, 'Resumen'))->widths([34, 50]);
        $resumen->bold('A'.$resumen->add(['Informe general · '.$t($meta['name'])]), 14);
        $resumen->add();
        $info = [
            ['Generado', $meta['generatedAt']],
            ['', ''],
            ['Assessments (sin borradores)', $s['assessments']],
            ['Con entregas', $s['withSubmissions']],
            ['Personas distintas', $s['people']],
            ['Invitaciones', $s['participants']],
            ['Entregas', $s['submitted']],
            ['Participación', $s['participationRate'], Sheets::PCT],
            ['Acierto promedio', $s['averageScore'], Sheets::PCT],
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
        $resumen->add();
        $row = $resumen->add(['Acierto: % de preguntas de selección respondidas con la opción esperada, promediado por persona. Solo entregas finales.']);
        $resumen->ws->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setARGB('FF5B6474');

        $list = (new Sheet($wb, 'Assessments'))->widths([44, 24, 12, 14, 12, 14, 12, 12]);
        $list->add(['Assessment', 'Periodo', 'Estado', 'Participantes', 'Entregas', 'Participación', 'Alineación', 'Acierto']);
        $list->header(1, 8);
        $list->freeze('A2');
        foreach ($report['assessments'] as $a) {
            $row = $list->add([
                $t($a['title']),
                self::period($a),
                ExportMeta::ASSESSMENT_STATUS_TEXT[$a['status']] ?? $a['status'],
                $a['participants'],
                $a['submitted'],
                $pct($a['participationRate']),
                $pct($a['averageAlignment']),
                $pct($a['averageScore']),
            ]);
            $list->numFmtCols($row, [6, 7, 8], Sheets::PCT);
        }

        $n = count($report['assessments']);
        foreach ([['Por área', 'Área', $report['byArea']], ['Por cargo', 'Cargo', $report['byCargo']]] as [$title, $label, $groups]) {
            $ws = (new Sheet($wb, $title))->widths([28, 12, 14, ...array_fill(0, $n, 18)]);
            $ws->add([$label, 'Entregas', 'Acierto total', ...array_map(fn ($a) => $t($a['title']), $report['assessments'])]);
            $ws->header(1, 3 + $n);
            $ws->freeze('A2');
            foreach ($groups as $g) {
                $row = $ws->add([
                    $t($g['name']),
                    $g['submissions'],
                    $pct($g['averageScore']),
                    ...array_map(fn ($a) => $pct($g['byAssessment'][$a['id']] ?? null), $report['assessments']),
                ]);
                $ws->numFmtCols($row, range(3, 3 + $n), Sheets::PCT);
            }
        }

        return Sheets::toBytes($wb);
    }

    // ---- PDF -------------------------------------------------------------------------

    public static function pdfHtml(array $meta, array $report): string
    {
        return Pdf::html('pdf.client', self::pdfData($meta, $report));
    }

    public static function pdf(array $meta, array $report): string
    {
        return Pdf::render('pdf.client', self::pdfData($meta, $report), "Informe general: {$meta['name']}", "Informe general · {$meta['name']}");
    }

    private static function pdfData(array $meta, array $report): array
    {
        return [
            ...Pdf::colors($meta),
            'documentTitle' => "Informe general: {$meta['name']}",
            'meta' => $meta,
            'report' => $report,
        ];
    }
}
