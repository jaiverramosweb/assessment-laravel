<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Security\Audit;
use App\Services\Ai\AiReportProps;
use App\Services\Ai\AiStore;
use App\Services\Exports\Download;
use App\Services\Exports\ExportMeta;
use App\Services\Exports\ReportExcel;
use App\Services\Exports\ReportPdf;
use App\Services\Reports\Reports;
use App\Services\Shared\AssessmentContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Reports tab of an assessment (filters by area/cargo) and its Excel/PDF downloads. */
class ReportController extends Controller
{
    public function index(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        ['filters' => $filters, 'areas' => $areas, 'cargos' => $cargos] = Reports::filtersFromQuery($a->id, $request->query('area'), $request->query('cargo'));
        // Answers per participant are only used by the exports: they are not sent to the browser.
        $report = [...Reports::build($a->id, $filters), 'answersByParticipant' => (object) []];
        $query = (string) $request->server('QUERY_STRING', '');

        return Inertia::render('Admin/Assessments/Reports', [
            'context' => AssessmentContext::props($a),
            'report' => $report,
            'areas' => $areas,
            'cargos' => $cargos,
            // So the downloads use the same filters as the screen.
            'query' => $query !== '' ? "?{$query}" : '',
            ...AiReportProps::for($a),
        ]);
    }

    public function excel(Request $request, string $id)
    {
        return $this->download($request, $id, 'xlsx');
    }

    public function pdf(Request $request, string $id)
    {
        return $this->download($request, $id, 'pdf');
    }

    /** Download of the report (with the URL filters), recorded in the audit log. */
    private function download(Request $request, string $id, string $format)
    {
        $a = AssessmentContext::require($id);
        ['filters' => $filters] = Reports::filtersFromQuery($a->id, $request->query('area'), $request->query('cargo'));
        $report = Reports::build($a->id, $filters);
        $meta = ExportMeta::forAssessment($a, $filters);

        $body = $format === 'xlsx'
            ? ReportExcel::build($meta, $report)
            : ReportPdf::build($meta, $report, AiStore::latest($a->id));

        Audit::log($request->attributes->get('admin')->id, 'reporte.exportado', 'assessment', $a->id, [
            'formato' => Download::LABELS[$format],
            'filtros' => $meta['filtersLabel'],
        ], $request->ip());

        return Download::response($body, $format, ExportMeta::fileName($a->title, $format));
    }
}
