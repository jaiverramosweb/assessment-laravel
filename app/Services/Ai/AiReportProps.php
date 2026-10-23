<?php

namespace App\Services\Ai;

use App\Models\Assessment;
use App\Models\Question;
use App\Services\Ai\Analysis\AiJobState;
use Throwable;

/**
 * AI props the reports page receives (merged into its Inertia props).
 * Contract used by the reports page and `AiAnalysisPanel.svelte`:
 * - `ai.configured`: the active provider has an API key and a model (`ai.model`: that model).
 * - `analyses`: AiStore::latest() keyed by question id or "global".
 * - `aiPending`: keys (question id or "global") with a queued/running analysis.
 * - `aiErrors`: key => Spanish message of the last failed analysis (job) or rejected request.
 * - `aiCached`: key whose answers did not change on the last click (the saved analysis is shown).
 */
final class AiReportProps
{
    public static function for(Assessment $assessment): array
    {
        $keys = [
            ...Question::where('assessment_id', $assessment->id)->where('type', 'abierta')->pluck('id')->all(),
            'global',
        ];
        $errors = AiJobState::errors($assessment->id, $keys);
        $flashError = self::flash('aiError');
        if (is_array($flashError) && is_string($flashError['target'] ?? null) && is_string($flashError['message'] ?? null)) {
            $errors[$flashError['target']] = $flashError['message'];
        }
        $cached = self::flash('aiCached');

        return [
            'ai' => [
                'configured' => AiSettings::configured(),
                'model' => AiSettings::model(),
            ],
            'analyses' => (object) AiStore::latest($assessment->id),
            'aiPending' => AiJobState::pending($assessment->id, $keys),
            'aiErrors' => (object) $errors,
            'aiCached' => is_string($cached) ? $cached : null,
        ];
    }

    private static function flash(string $key): mixed
    {
        try {
            return session()->get($key);
        } catch (Throwable) {
            return null;
        }
    }
}
