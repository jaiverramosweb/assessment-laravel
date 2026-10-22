<?php

namespace App\Services\Exports;

use App\Models\Assessment;
use App\Services\Reports\Format;
use App\Support\Clock;

/**
 * Exports of the comparison between measurements, Excel and PDF
 * (port of src/lib/server/export/comparison-export.ts; view: resources/views/pdf/comparison.blade.php).
 */
final class ComparisonExport
{
    public const PP = '+0" pp";-0" pp";0" pp"';

    /**
     * Title of the series (without the " · Medición N" suffix), logo and colors of the client of
     * the first measurement.
     *
     * @return array{title: string, generatedAt: string, logoDataUrl: ?string, themePrimary: ?string, themeAccent: ?string}
     */
    public static function meta(Assessment $first, ?int $nowMs = null): array
    {
        return [
            'title' => preg_replace('/ · Medición \d+$/u', '', $first->title),
            'generatedAt' => Format::longDateTime($nowMs ?? Clock::nowMs(), $first->timezone),
            ...ExportMeta::brand($first->client_id),
        ];
    }

    public static function modeText(array $c): string
    {
        return $c['mode'] === 'pareado'
            ? "Solo personas que respondieron todas las mediciones ({$c['summary']['paired']})"
            : 'Todas las entregas de cada medición';
    }

    public static function label(array $m): string
    {
        return "Medición {$m['order']} (".Format::shortDate($m['startsAt'], $m['timezone']).')';
    }

    /** Difference in points (a number, for Excel's "+12 pp" format). */
    private static function points(?float $d): int|string
    {
        return $d === null ? '—' : (int) round($d * 100);
    }

    private static function pct(int|float|null $v): int|float|string
    {
        return $v ?? '—';
    }

    // ---- Excel ---------------------------------------------------------------------

    public static function excel(array $meta, array $c): string
    {
        $wb = Sheets::workbook();
        $t = [Sheets::class, 'safeText'];
        $n = count($c['measurements']);
        $ms = array_map([self::class, 'label'], $c['measurements']);
        $range = fn (int $from) => $n ? range($from, $from + $n - 1) : [];
        $sheet = function (string $name, array $headers, array $widths) use ($wb): Sheet {
            $ws = (new Sheet($wb, $name))->widths($widths);
            $ws->add($headers);
            $ws->header(1, count($headers));
            $ws->freeze('A2');

            return $ws;
        };

        $resumen = (new Sheet($wb, 'Resumen'))->widths([36, ...array_fill(0, $n, 22), 14]);
        $resumen->bold('A'.$resumen->add(['Comparativo · '.$t($meta['title'])]), 14);
        $resumen->add(['Personas incluidas', self::modeText($c)]);
        $resumen->add(['Generado', $meta['generatedAt']]);
        $resumen->add();
        $head = $resumen->add(['', ...array_map($t, $ms), 'Diferencia']);
        $resumen->bold(Sheet::range($head, $n + 2));
        $resumen->fill(Sheet::range($head, $n + 2), Sheets::HEADER_FILL);
        $resumen->add(['Assessment', ...array_map(fn ($m) => $t($m['title']), $c['measurements'])]);
        $resumen->add(['Entregas incluidas', ...array_column($c['measurements'], 'submitted')]);
        foreach ([['Acierto promedio', 'averageScore', 'delta'], ['Alineación promedio', 'averageAlignment', 'alignmentDelta']] as [$label, $key, $delta]) {
            $row = $resumen->add([
                $label,
                ...array_map(fn ($m) => self::pct($m[$key]), $c['measurements']),
                self::points($c['summary'][$delta]),
            ]);
            $resumen->numFmtCols($row, $range(2), Sheets::PCT);
            $resumen->numFmt(Sheet::col($n + 2).$row, self::PP);
        }

        $preguntas = $sheet(
            'Por pregunta',
            ['#', 'Pregunta', ...$ms, 'Diferencia', 'Mejoraron', 'Siguieron bien', 'Empeoraron', 'Siguieron mal'],
            [5, 60, ...array_fill(0, $n, 16), 12, 12, 14, 12, 14],
        );
        foreach ($c['choice'] as $q) {
            $tr = $q['transitions'];
            $row = $preguntas->add([
                $q['position'],
                $t($q['prompt']),
                ...array_map([self::class, 'pct'], $q['rates']),
                self::points($q['delta']),
                $tr['improved'] ?? '—',
                $tr['keptRight'] ?? '—',
                $tr['worsened'] ?? '—',
                $tr['keptWrong'] ?? '—',
            ]);
            $preguntas->numFmtCols($row, $range(3), Sheets::PCT);
            $preguntas->numFmt(Sheet::col($n + 3).$row, self::PP);
            foreach ($q['options'] as $o) {
                $row = $preguntas->add([
                    '',
                    '   '.($o['isExpected'] ? '✓ ' : '').$t($o['text']),
                    ...array_map([self::class, 'pct'], $o['rates']),
                ]);
                $preguntas->ws->getStyle(Sheet::range($row, $n + 2))->getFont()->getColor()
                    ->setARGB($o['isExpected'] ? 'FF2A3CC9' : 'FF5B6474');
                $preguntas->numFmtCols($row, $range(3), Sheets::PCT);
            }
        }

        $personas = $sheet(
            'Por persona',
            ['Nombre', 'Email', 'Área', 'Cargo', ...$ms, 'Diferencia'],
            [28, 32, 18, 18, ...array_fill(0, $n, 16), 12],
        );
        foreach ($c['people'] as $p) {
            $row = $personas->add([
                $t($p['name']),
                $t($p['email']),
                $t($p['area']),
                $t($p['cargo']),
                ...array_map([self::class, 'pct'], $p['scores']),
                self::points($p['delta']),
            ]);
            $personas->numFmtCols($row, $range(5), Sheets::PCT);
            $personas->numFmt(Sheet::col($n + 5).$row, self::PP);
        }

        foreach ([['Por área', 'Área', $c['byArea']], ['Por cargo', 'Cargo', $c['byCargo']]] as [$name, $col, $groups]) {
            $ws = $sheet(
                $name,
                [$col, ...array_merge(...array_map(fn ($m) => [$m, 'Entregas'], $ms ?: [])), 'Diferencia'],
                [26, ...array_merge(...array_fill(0, max($n, 1), [16, 10])), 12],
            );
            foreach ($groups as $g) {
                $cells = [];
                foreach ($g['scores'] as $i => $score) {
                    $cells[] = self::pct($score);
                    $cells[] = $g['counts'][$i];
                }
                $row = $ws->add([$t($g['name']), ...$cells, self::points($g['delta'])]);
                $ws->numFmtCols($row, array_map(fn ($i) => 2 + $i * 2, $n ? range(0, $n - 1) : []), Sheets::PCT);
                $ws->numFmt(Sheet::col(2 * $n + 2).$row, self::PP);
            }
        }

        if ($c['open']) {
            $abiertas = $sheet('Respuestas abiertas', ['Pregunta', 'Persona', ...$ms], [40, 26, ...array_fill(0, $n, 50)]);
            foreach ($c['open'] as $q) {
                foreach ($q['responses'] as $r) {
                    $row = $abiertas->add([
                        $t("{$q['position']}. {$q['prompt']}"),
                        $t($r['name']),
                        ...array_map(fn ($x) => $t($x ?? ''), $r['texts']),
                    ]);
                    $abiertas->wrapTop(Sheet::range($row, 2 + $n));
                }
            }
        }

        return Sheets::toBytes($wb);
    }

    // ---- PDF -------------------------------------------------------------------------

    public static function pdfHtml(array $meta, array $c): string
    {
        return Pdf::html('pdf.comparison', self::pdfData($meta, $c));
    }

    public static function pdf(array $meta, array $c): string
    {
        return Pdf::render('pdf.comparison', self::pdfData($meta, $c), "Comparativo: {$meta['title']}", "Comparativo · {$meta['title']}");
    }

    private static function pdfData(array $meta, array $c): array
    {
        return [
            ...Pdf::colors($meta),
            'documentTitle' => "Comparativo: {$meta['title']}",
            'meta' => $meta,
            'c' => $c,
        ];
    }
}
