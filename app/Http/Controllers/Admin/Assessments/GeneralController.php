<?php

namespace App\Http\Controllers\Admin\Assessments;

use App\Http\Controllers\Controller;
use App\Services\Assessments\AssessmentForm;
use App\Services\Assessments\Assessments;
use App\Services\Assessments\LocalDateTime;
use App\Services\Assessments\Series;
use App\Services\Shared\AssessmentContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * General tab of an assessment: data, access password, status, series, duplicate and delete.
 * Action outcomes travel to the page as the one-shot `result` prop (session flash);
 * field errors go through the validation error bag.
 */
class GeneralController extends Controller
{
    public function show(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);

        return Inertia::render('Admin/Assessments/General', [
            'context' => AssessmentContext::props($a),
            'values' => AssessmentForm::fromAssessment($a),
            'timezones' => LocalDateTime::allTimeZones(),
            'clients' => Assessments::clientOptions(),
            'publishProblems' => Assessments::publishProblems($a),
            'deletion' => Assessments::deletionSummary($a->id),
            'result' => $request->session()->get('result'),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $result = AssessmentForm::validate(AssessmentForm::read($request));
        if (isset($result['errors'])) {
            return back(303)->withErrors($result['errors']);
        }
        if ($result['data']['client_id'] && ! Assessments::clientExists($result['data']['client_id'])) {
            return back(303)->withErrors(['clientId' => 'El cliente elegido no existe.']);
        }

        Assessments::update($a->id, $result['data'], $this->adminId($request), $request->ip());

        return $this->toGeneral($a->id, ['saved' => true]);
    }

    public function password(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $errors = AssessmentForm::validateAccessPassword($request->input('password'), $request->input('confirm'));
        if ($errors) {
            return back(303)->withErrors($errors);
        }

        Assessments::setAccessPassword($a->id, $request->input('password'), $this->adminId($request), $request->ip());

        return $this->toGeneral($a->id, ['passwordSaved' => true]);
    }

    /** JSON: the password never goes through the session; each view is audited. */
    public function revealPassword(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $password = Assessments::revealAccessPassword($a, $this->adminId($request), $request->ip());
        if ($password === null) {
            return response()->json([
                'error' => 'Esta contraseña se definió antes de poder consultarse. Defínala de nuevo para poder verla.',
            ], 400);
        }

        return response()->json(['password' => $password])->header('Cache-Control', 'no-store');
    }

    public function publish(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $problems = Assessments::publishProblems($a);
        if ($problems) {
            return $this->toGeneral($a->id, ['publishErrors' => $problems]);
        }
        Assessments::setStatus($a, 'publicado', $this->adminId($request), $request->ip());

        return $this->toGeneral($a->id, ['statusChanged' => 'publicado']);
    }

    public function draft(Request $request, string $id)
    {
        return $this->changeStatus($request, $id, 'borrador');
    }

    public function archive(Request $request, string $id)
    {
        return $this->changeStatus($request, $id, 'archivado');
    }

    /** Permanent deletion: the exact title must be typed as confirmation. */
    public function destroy(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $typed = trim((string) $request->input('confirmacion', ''));
        if ($typed !== trim($a->title)) {
            return back(303)->withErrors([
                'confirmacion' => 'Escriba el título exacto del assessment para confirmar la eliminación.',
            ]);
        }
        Assessments::delete($a, $this->adminId($request), $request->ip());

        return redirect('/admin?eliminado=1', 303);
    }

    /** Next measurement of the series (same survey, to compare before and after). */
    public function nextMeasurement(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        if (AssessmentContext::countQuestions($a->id)['total'] === 0) {
            return back(303)->withErrors(['medicion' => 'Agregue preguntas antes de crear otra medición.']);
        }
        $next = Series::createNextMeasurement($a->id, $this->adminId($request), $request->ip());
        if (! $next) {
            abort(404, 'Assessment no encontrado');
        }

        return redirect("/admin/assessments/{$next->id}?medicion=1", 303);
    }

    public function duplicate(Request $request, string $id)
    {
        AssessmentContext::require($id);
        $copy = Assessments::duplicate($id, $this->adminId($request), $request->ip());
        if (! $copy) {
            abort(404, 'Assessment no encontrado');
        }

        return redirect("/admin/assessments/{$copy->id}?duplicado=1", 303);
    }

    private function changeStatus(Request $request, string $id, string $status)
    {
        $a = AssessmentContext::require($id);
        Assessments::setStatus($a, $status, $this->adminId($request), $request->ip());

        return $this->toGeneral($a->id, ['statusChanged' => $status]);
    }

    private function toGeneral(string $id, array $result)
    {
        return redirect("/admin/assessments/{$id}", 303)->with('result', $result);
    }

    private function adminId(Request $request): string
    {
        return $request->attributes->get('admin')->id;
    }
}
