<?php

namespace App\Services\Exports;

/**
 * Report PDF: cover, summary, chart and table per question, and the AI analysis of the open
 * questions (port of src/lib/server/export/pdf.ts; view: resources/views/pdf/report.blade.php).
 */
final class ReportPdf
{
    /** @param  array<string, array>  $analyses  AiStore::latest() of the assessment. */
    public static function html(array $meta, array $report, array $analyses): string
    {
        return Pdf::html('pdf.report', self::data($meta, $report, $analyses));
    }

    public static function build(array $meta, array $report, array $analyses): string
    {
        return Pdf::render('pdf.report', self::data($meta, $report, $analyses), "Informe: {$meta['title']}", $meta['title']);
    }

    private static function data(array $meta, array $report, array $analyses): array
    {
        return [
            ...Pdf::colors($meta),
            'documentTitle' => "Informe: {$meta['title']}",
            'meta' => $meta,
            'report' => $report,
            'analyses' => $analyses,
        ];
    }
}
