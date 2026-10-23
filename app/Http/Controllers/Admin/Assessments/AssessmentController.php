<?php

namespace App\Http\Controllers\Admin\Assessments;

use App\Auth\AdminAccess;
use App\Http\Controllers\Controller;
use App\Services\Assessments\AssessmentForm;
use App\Services\Assessments\Assessments;
use App\Services\Assessments\LocalDateTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Dashboard (/admin) and creation of assessments. */
class AssessmentController extends Controller
{
    public function index(Request $request)
    {
        $includeArchived = $request->query('archivados') === '1';
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        // Limited admins only see the clients (and assessments) whose reports they were granted.
        $admin = $request->attributes->get('admin');
        $visible = fn (?string $clientId) => AdminAccess::canViewReportsOf($admin, $clientId);
        $clients = array_values(array_filter(Assessments::clientOptions(), fn ($c) => $visible($c['id'])));
        $clientParam = (string) $request->query('cliente', '');
        $clientId = in_array($clientParam, array_column($clients, 'id'), true) ? $clientParam : '';

        $listed = array_filter(Assessments::listForDashboard($includeArchived, $clientId ?: null), fn ($a) => $visible($a['clientId']));
        $all = array_values(array_map(fn (array $a) => [
            ...$a,
            // Alignment is only computed when there are submissions (avoids useless work).
            'averageAlignment' => $a['submittedCount'] ? Assessments::averageAlignment($a['id']) : null,
        ], $listed));

        $assessments = $q === ''
            ? $all
            : array_values(array_filter($all, fn ($a) => str_contains(mb_strtolower($a['title']), $q)));

        $active = array_values(array_filter($all, fn ($a) => $a['status'] !== 'archivado'));
        $sum = fn (string $key) => array_sum(array_column($active, $key));
        $withAlignment = array_values(array_filter($active, fn ($a) => $a['averageAlignment'] !== null));
        $participants = $sum('participantCount');
        $submitted = $sum('submittedCount');

        return Inertia::render('Admin/Assessments/Index', [
            'includeArchived' => $includeArchived,
            'q' => $q,
            'clients' => $clients,
            'clientId' => $clientId,
            'assessments' => $assessments,
            'kpis' => [
                'published' => count(array_filter($active, fn ($a) => $a['status'] === 'publicado')),
                'drafts' => count(array_filter($active, fn ($a) => $a['status'] === 'borrador')),
                'submitted' => $submitted,
                'inProgress' => $sum('inProgressCount'),
                'participants' => $participants,
                'participationRate' => $participants ? (float) ($submitted / $participants) : null,
                'averageAlignment' => $withAlignment
                    ? (float) (array_sum(array_column($withAlignment, 'averageAlignment')) / count($withAlignment))
                    : null,
                'alignmentCount' => count($withAlignment),
            ],
        ]);
    }

    public function create(Request $request)
    {
        // "Nuevo assessment" from a client's page arrives with ?cliente=<id>.
        $preset = (string) $request->query('cliente', '');

        return Inertia::render('Admin/Assessments/Create', [
            'timezones' => LocalDateTime::allTimeZones(),
            'clients' => Assessments::clientOptions(),
            'values' => AssessmentForm::defaults($preset !== '' && Assessments::clientExists($preset) ? $preset : ''),
        ]);
    }

    public function store(Request $request)
    {
        $result = AssessmentForm::validate(AssessmentForm::read($request));
        if (isset($result['errors'])) {
            return back(303)->withErrors($result['errors']);
        }
        if ($result['data']['client_id'] && ! Assessments::clientExists($result['data']['client_id'])) {
            return back(303)->withErrors(['clientId' => 'El cliente elegido no existe.']);
        }

        $admin = $request->attributes->get('admin');
        $created = Assessments::create($result['data'], $admin->id, $request->ip());

        return redirect("/admin/assessments/{$created->id}/preguntas?creado=1", 303);
    }
}
