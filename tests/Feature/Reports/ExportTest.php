<?php

namespace Tests\Feature\Reports;

use App\Models\Assessment;
use App\Models\Client;
use App\Services\Ai\AiStore;
use App\Services\Exports\ClientExport;
use App\Services\Exports\ComparisonExport;
use App\Services\Exports\ExportMeta;
use App\Services\Exports\ReportExcel;
use App\Services\Exports\ReportPdf;
use App\Services\Exports\Sheets;
use App\Services\Reports\ClientReport;
use App\Services\Reports\Comparison;
use App\Services\Reports\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Fixtures\Reports\Scenarios;
use Tests\TestCase;

/**
 * Port of src/lib/server/export/export.spec.ts, plus the export cases of client-report.spec.ts
 * and series.spec.ts.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = 1793966400000; // 2026-11-06T12:00:00Z

    public static function load(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    /** Row values from column A (like ExcelJS `row.values.slice(1)`), trailing empties dropped. */
    public static function row(Spreadsheet $wb, string $sheet, int $row): array
    {
        $ws = $wb->getSheetByName($sheet);
        $values = $ws->rangeToArray('A'.$row.':'.$ws->getHighestColumn().$row, null, false, false)[0];
        while ($values && end($values) === null) {
            array_pop($values);
        }

        return $values;
    }

    private function meta(Assessment $a): array
    {
        return ExportMeta::forAssessment($a, [], self::NOW);
    }

    public function test_excel_has_the_four_sheets_with_the_report_data(): void
    {
        $a = Scenarios::export();
        $wb = self::load(ReportExcel::build($this->meta($a), Reports::build($a->id)));
        $this->assertSame(['Resumen', 'Por pregunta', 'Por participante', 'Respuestas abiertas'], $wb->getSheetNames());

        $resumen = $wb->getSheetByName('Resumen');
        $this->assertSame('Seguridad de la Información', $resumen->getCell('A1')->getValue());
        $tasa = null;
        foreach ($resumen->getRowIterator() as $r) {
            if ($resumen->getCell('A'.$r->getRowIndex())->getValue() === 'Tasa de participación') {
                $tasa = $r->getRowIndex();
            }
        }
        $this->assertNotNull($tasa);
        $this->assertEquals(1, $resumen->getCell('B'.$tasa)->getValue());
        $this->assertSame('0%', $resumen->getCell('B'.$tasa)->getStyle()->getNumberFormat()->getFormatCode());

        $this->assertEquals([1, '¿Qué es confidencialidad?', 'Acceso de autorizados', 'Sí', 2, 1], self::row($wb, 'Por pregunta', 2));

        $porParticipante = $wb->getSheetByName('Por participante');
        $this->assertSame('P1', $porParticipante->getCell('H1')->getValue());
        $names = [$porParticipante->getCell('A2')->getValue(), $porParticipante->getCell('A3')->getValue()];
        $this->assertContains('\'=HYPERLINK("http://x")', $names);
        $this->assertSame('Acceso de autorizados', $porParticipante->getCell('H2')->getValue());
        // The submission date shows in Bogotá time (15:30 UTC = 10:30).
        $date = ExcelDate::excelToDateTimeObject($porParticipante->getCell('G2')->getValue());
        $this->assertSame('2026-11-05 10:30', $date->format('Y-m-d H:i'));
        $this->assertSame('yyyy-mm-dd hh:mm', $porParticipante->getCell('G2')->getStyle()->getNumberFormat()->getFormatCode());

        $abiertas = $wb->getSheetByName('Respuestas abiertas');
        $this->assertSame(3, $abiertas->getHighestDataRow());
        $this->assertStringStartsWith('Respuesta de ', $abiertas->getCell('G2')->getValue());
    }

    public function test_neutralizes_text_excel_would_read_as_a_formula(): void
    {
        $this->assertSame("'=1+1", Sheets::safeText('=1+1'));
        $this->assertSame("'+57 300", Sheets::safeText('+57 300'));
        $this->assertSame('Normal', Sheets::safeText('Normal'));
        $this->assertSame('', Sheets::safeText(null));
    }

    public function test_builds_a_safe_file_name(): void
    {
        $this->assertSame(
            'informe-seguridad-de-la-informacion-2026-2026-11-06.pdf',
            ExportMeta::fileName('Seguridad de la Información / 2026', 'pdf', self::NOW),
        );
        $this->assertSame('informe-assessment-2026-11-06.xlsx', ExportMeta::fileName('¿?', 'xlsx', self::NOW));
    }

    public function test_meta_describes_period_filters_and_brand(): void
    {
        $client = Client::create(['name' => 'Acme', 'theme_primary' => '#112233', 'theme_accent' => 'rojo']);
        $a = Scenarios::assessment(['title' => 'Con marca', 'client_id' => $client->id]);
        $meta = ExportMeta::forAssessment($a, ['area' => 'TI', 'cargo' => 'Analista'], self::NOW);
        $this->assertSame("Del 1 de noviembre de 2026, 8:00\u{00A0}a.\u{00A0}m. al 15 de noviembre de 2026, 6:00\u{00A0}p.\u{00A0}m.", $meta['period']);
        $this->assertSame('Área: TI · Cargo: Analista', $meta['filtersLabel']);
        $this->assertSame('#112233', $meta['themePrimary']);
        $this->assertNull($meta['themeAccent']);
        $this->assertNull($meta['logoDataUrl']);
        $this->assertSame('Todos los participantes', ExportMeta::forAssessment($a, [], self::NOW)['filtersLabel']);
    }

    public function test_pdf_is_valid(): void
    {
        $a = Scenarios::export();
        $pdf = ReportPdf::build($this->meta($a), Reports::build($a->id), AiStore::latest($a->id));
        $this->assertSame('%PDF-', substr($pdf, 0, 5));
        $this->assertGreaterThan(5000, strlen($pdf));
        // To check the design by hand: SAVE_PDF=1 php artisan test --filter=test_pdf_is_valid
        if (getenv('SAVE_PDF')) {
            file_put_contents(sys_get_temp_dir().'/informe-con-ia.pdf', $pdf);
        }
    }

    public function test_pdf_includes_cover_summary_questions_and_the_stored_ai_analysis(): void
    {
        $a = Scenarios::export();
        $html = ReportPdf::html($this->meta($a), Reports::build($a->id), AiStore::latest($a->id));
        foreach ([
            'Informe de resultados',
            'Seguridad de la Información',
            'Resumen general',
            'Resultados por pregunta',
            '(esperada)',
            'Las personas valoran la capacitación.',
            'Capacitación',
            'Necesitamos más práctica',
            'claude-opus-5-5',
        ] as $expected) {
            $this->assertStringContainsString(e($expected), $html);
        }
        // The PDF never includes names or emails of who answered.
        $this->assertStringNotContainsString('ana@x.co', $html);
    }

    public function test_pdf_says_when_an_open_question_has_no_analysis_yet(): void
    {
        $a = Scenarios::export();
        $html = ReportPdf::html($this->meta($a), Reports::build($a->id), []);
        $this->assertStringContainsString('Aún no se ha generado el análisis con IA', $html);
    }

    public function test_pdf_escapes_user_text(): void
    {
        $a = Scenarios::assessment(['title' => '<img src="http://x/y.png">Hola']);
        $html = ReportPdf::html($this->meta($a), Reports::build($a->id), []);
        $this->assertStringNotContainsString('<img src="http://x', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_client_excel_has_four_sheets_and_the_pdf_renders(): void
    {
        ['client' => $client] = Scenarios::client();
        $meta = ClientExport::meta($client, self::NOW);
        $report = ClientReport::build($client->id);

        $wb = self::load(ClientExport::excel($meta, $report));
        $this->assertSame(['Resumen', 'Assessments', 'Por área', 'Por cargo'], $wb->getSheetNames());
        $list = $wb->getSheetByName('Assessments');
        $this->assertSame('Antes', $list->getCell('A2')->getValue());
        $this->assertEquals(0.5, $list->getCell('H2')->getValue());
        $this->assertSame(['Área', 'Entregas', 'Acierto total', 'Antes', 'Después'], self::row($wb, 'Por área', 1));
        $this->assertSame('Informe general · Acme', $wb->getSheetByName('Resumen')->getCell('A1')->getValue());

        $html = ClientExport::pdfHtml($meta, $report);
        foreach (['Informe general', 'Acme', 'Acierto promedio por assessment', 'Detalle por assessment', 'Por área', 'Sin dato'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $pdf = ClientExport::pdf($meta, $report);
        $this->assertSame('%PDF-', substr($pdf, 0, 5));
        if (getenv('SAVE_PDF')) {
            file_put_contents(sys_get_temp_dir().'/informe-general.pdf', $pdf);
        }
    }

    public function test_comparison_excel_and_pdf(): void
    {
        ['pre' => $pre] = Scenarios::series();
        $c = Comparison::build($pre);
        $meta = ['title' => 'Diagnóstico', 'generatedAt' => 'hoy', 'logoDataUrl' => null, 'themePrimary' => null, 'themeAccent' => null];

        $wb = self::load(ComparisonExport::excel($meta, $c));
        $this->assertSame(['Resumen', 'Por pregunta', 'Por persona', 'Por área', 'Por cargo', 'Respuestas abiertas'], $wb->getSheetNames());
        $this->assertEquals([1, 'P1', 0.5, 1, 50, 1, 1, 0, 0], self::row($wb, 'Por pregunta', 2));
        $this->assertSame('+0" pp";-0" pp";0" pp"', $wb->getSheetByName('Por pregunta')->getCell('E2')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('   ✓ Sí', $wb->getSheetByName('Por pregunta')->getCell('B3')->getValue());

        $html = ComparisonExport::pdfHtml($meta, $c);
        foreach (['Comparativo entre mediciones', 'Diagnóstico', 'Acierto: antes y después', '+50 pp', 'Acierto por persona', 'Mejoraron / empeoraron'] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
        $pdf = ComparisonExport::pdf($meta, $c);
        $this->assertSame('%PDF-', substr($pdf, 0, 5));
        if (getenv('SAVE_PDF')) {
            file_put_contents(sys_get_temp_dir().'/comparativo.pdf', $pdf);
        }
    }
}
