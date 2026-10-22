<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Security\Audit;
use App\Services\Exports\ComparisonExport;
use App\Services\Exports\Download;
use App\Services\Exports\ExportMeta;
use App\Services\Reports\Comparison;
use App\Services\Shared\AssessmentContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Mediciones tab: comparison between the measurements of a series and its downloads. */
class MeasurementController extends Controller
{
    public function index(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $members = Comparison::members($a);
        if ($members->count() < 2) {
            return Inertia::render('Admin/Assessments/Measurements', [
                'context' => AssessmentContext::props($a),
                'comparison' => null,
                'query' => '',
            ]);
        }
        $query = (string) $request->server('QUERY_STRING', '');
        ['mode' => $mode, 'selectedIds' => $selected] = Comparison::params($query, $members->pluck('id')->all());

        return Inertia::render('Admin/Assessments/Measurements', [
            'context' => AssessmentContext::props($a),
            'comparison' => Comparison::build($a, $mode, $selected),
            // So the downloads use the same mode and measurements as the screen.
            'query' => $query !== '' ? "?{$query}" : '',
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

    /** Download of the comparison (same mode and measurements as the URL), recorded in the audit log. */
    private function download(Request $request, string $id, string $format)
    {
        $a = AssessmentContext::require($id);
        $members = Comparison::members($a);
        if ($members->count() < 2) {
            abort(404, 'Este assessment no tiene otras mediciones');
        }
        ['mode' => $mode, 'selectedIds' => $selected] = Comparison::params((string) $request->server('QUERY_STRING', ''), $members->pluck('id')->all());
        $comparison = Comparison::build($a, $mode, $selected);
        $meta = ComparisonExport::meta($members->first());

        $body = $format === 'xlsx'
            ? ComparisonExport::excel($meta, $comparison)
            : ComparisonExport::pdf($meta, $comparison);

        Audit::log($request->attributes->get('admin')->id, 'reporte.exportado', 'assessment', $a->id, [
            'formato' => Download::LABELS[$format],
            'informe' => 'comparativo de mediciones',
            'mediciones' => implode(', ', array_column($comparison['measurements'], 'order')),
        ], $request->ip());

        return Download::response($body, $format, ExportMeta::fileName("comparativo {$meta['title']}", $format));
    }
}
