<?php

namespace App\Http\Controllers\Admin\Participants;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Services\Import\ImportFileError;
use App\Services\Import\Table;
use App\Services\Participants\ParticipantExistsError;
use App\Services\Participants\ParticipantHasResponsesError;
use App\Services\Participants\ParticipantImport;
use App\Services\Participants\ParticipantInput;
use App\Services\Participants\Participants;
use App\Services\Shared\AssessmentContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Participants tab: list with filters, manual add, Excel/CSV import, delete and template.
 * Action results come back as the one-shot `outcome` prop (like the original form actions).
 */
class ParticipantController extends Controller
{
    public function index(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $all = Participants::listParticipants($a->id);

        $filters = [
            'estado' => (string) $request->query('estado', ''),
            'area' => (string) $request->query('area', ''),
            'cargo' => (string) $request->query('cargo', ''),
            'q' => mb_strtolower(trim((string) $request->query('q', ''))),
        ];
        $filtered = array_values(array_filter($all, fn ($p) => ($filters['estado'] === '' || $p['status'] === $filters['estado'])
            && ($filters['area'] === '' || $p['area'] === $filters['area'])
            && ($filters['cargo'] === '' || $p['cargo'] === $filters['cargo'])
            && ($filters['q'] === ''
                || str_contains(mb_strtolower($p['name']), $filters['q'])
                || str_contains($p['email'], $filters['q']))));

        return Inertia::render('Admin/Assessments/Participants', [
            'context' => AssessmentContext::props($a),
            'participants' => $filtered,
            'counts' => Participants::counts($all),
            'filters' => $filters,
            'statuses' => Participants::STATUSES,
            ...Participants::distinctAreasAndCargos($a->id),
            'outcome' => $request->session()->get('outcome'),
        ]);
    }

    public function store(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $parsed = ParticipantInput::validate([
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
            'area' => (string) $request->input('area', ''),
            'cargo' => (string) $request->input('cargo', ''),
        ]);
        if (! $parsed['ok']) {
            return back(303)->withErrors($parsed['errors']);
        }

        try {
            Participants::add($a->id, $parsed['data'], $request->attributes->get('admin')->id, $request->ip());
        } catch (ParticipantExistsError $e) {
            return back(303)->withErrors(['email' => $e->getMessage()]);
        }

        return back(303)->with('outcome', ['added' => $parsed['data']['email']]);
    }

    public function import(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $file = $request->file('archivo');
        if (! $file) {
            return back(303)->with('outcome', ['importErrors' => ['Seleccione un archivo.']]);
        }

        try {
            $result = ParticipantImport::parse(Table::read($file));
        } catch (ImportFileError $e) {
            return back(303)->with('outcome', ['importErrors' => [$e->getMessage()]]);
        }
        if (! $result['ok']) {
            return back(303)->with('outcome', ['importErrors' => $result['errors']]);
        }

        $counts = Participants::upsert($a->id, $result['participants'], $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('outcome', ['imported' => $counts]);
    }

    /** Edits name, area and cargo from the table. The email stays (it is not sent to the validator). */
    public function update(Request $request, string $id, string $participantId)
    {
        $a = AssessmentContext::require($id);
        $current = Participant::where('id', $participantId)->where('assessment_id', $a->id)->first();
        if (! $current) {
            return back(303)->with('outcome', ['editError' => 'El participante no existe.']);
        }

        $parsed = ParticipantInput::validate([
            'name' => (string) $request->input('name', ''),
            'email' => $current->email,
            'area' => (string) $request->input('area', ''),
            'cargo' => (string) $request->input('cargo', ''),
        ]);
        if (! $parsed['ok']) {
            return back(303)->withErrors($parsed['errors']);
        }

        if (! Participants::update($a->id, $current->id, $parsed['data'], $request->attributes->get('admin')->id, $request->ip())) {
            return back(303)->with('outcome', ['editError' => 'El participante no existe.']);
        }

        return back(303)->with('outcome', ['edited' => $parsed['data']['name']]);
    }

    public function destroy(Request $request, string $id, string $participantId)
    {
        $a = AssessmentContext::require($id);
        try {
            $ok = Participants::delete($a->id, $participantId, $request->attributes->get('admin')->id, $request->ip());
        } catch (ParticipantHasResponsesError $e) {
            return back(303)->with('outcome', ['deleteError' => $e->getMessage()]);
        }
        if (! $ok) {
            return back(303)->with('outcome', ['deleteError' => 'El participante no existe.']);
        }

        return back(303)->with('outcome', ['deleted' => true]);
    }

    /** Excel template to load participants. */
    public function template(string $id)
    {
        AssessmentContext::require($id);

        return response(ParticipantImport::template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-participantes.xlsx"',
        ]);
    }
}
