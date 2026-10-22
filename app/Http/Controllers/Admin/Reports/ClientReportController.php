<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Security\Audit;
use App\Services\Clients\ClientService;
use App\Services\Exports\ClientExport;
use App\Services\Exports\Download;
use App\Services\Exports\ExportMeta;
use App\Services\Reports\ClientReport;
use App\Services\Shared\Brand;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** General report of a client (all of its non-draft assessments) and its downloads. */
class ClientReportController extends Controller
{
    public function index(string $id)
    {
        $c = ClientService::require($id);

        return Inertia::render('Admin/Clients/Report', [
            'client' => ['id' => $c->id, 'name' => $c->name, 'logoUrl' => Brand::clientLogoUrl($c)],
            'report' => ClientReport::build($c->id),
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

    /** Download of the client's general report, recorded in the audit log. */
    private function download(Request $request, string $id, string $format)
    {
        $client = ClientService::require($id);
        $report = ClientReport::build($client->id);
        $meta = ClientExport::meta($client);
        $body = $format === 'xlsx' ? ClientExport::excel($meta, $report) : ClientExport::pdf($meta, $report);

        Audit::log($request->attributes->get('admin')->id, 'reporte.exportado', 'cliente', $client->id, [
            'formato' => Download::LABELS[$format],
            'informe' => 'general del cliente',
        ], $request->ip());

        return Download::response($body, $format, ExportMeta::fileName("general {$client->name}", $format));
    }
}
