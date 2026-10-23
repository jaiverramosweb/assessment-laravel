<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeOpenAnswers;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Analysis\AiAnalysisService;
use App\Services\Ai\Analysis\AiError;
use App\Services\Ai\Analysis\AiJobState;
use App\Services\Shared\AssessmentContext;
use Illuminate\Http\Request;

/**
 * "Analizar con IA" / "Regenerar" on the reports page (the original `?/analizar` and
 * `?/analizarGlobal` actions). The request only validates and queues: the AI call runs in the
 * AnalyzeOpenAnswers job. Results reach the page through AiReportProps:
 * - flash `aiCached` (key): the answers did not change, the saved analysis is shown.
 * - flash `aiError` ({target, message}): the analysis could not be queued.
 */
class AiAnalysisController extends Controller
{
    public function analyze(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $raw = $request->input('questionId');
        $questionId = is_string($raw) && $raw !== '' ? $raw : null;
        $key = $questionId ?? 'global';
        $fail = fn (string $message) => back(303)->with('aiError', ['target' => $key, 'message' => $message]);

        if ($raw !== null && $raw !== '' && $questionId === null) {
            return $fail('La pregunta no existe o no es de respuesta abierta.');
        }
        if (! AiSettings::configured()) {
            return $fail(AiSettings::NOT_CONFIGURED);
        }

        try {
            $check = AiAnalysisService::check($a->id, $questionId, AiSettings::model());
        } catch (AiError $e) {
            return $fail($e->getMessage());
        }
        $force = $request->boolean('regenerar');
        if (! $force && $check['cached']) {
            return back(303)->with('aiCached', $key);
        }

        if (! AiJobState::markPending($a->id, $key)) {
            return $fail('Ya hay un análisis en curso para esta pregunta. Espere a que termine.');
        }
        AiJobState::clearError($a->id, $key);
        AnalyzeOpenAnswers::dispatch($a->id, $questionId, $request->attributes->get('admin')->id, $force, $request->ip());

        return back(303);
    }
}
