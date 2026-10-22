<?php

namespace App\Services\Ai;

use App\Models\Assessment;

/**
 * AI props the reports page receives (merged into its Inertia props).
 * Contract used by the reports page and `AiAnalysisPanel.svelte`:
 * - `ai.configured`: an API key is set.
 * - `analyses`: AiStore::latest() keyed by question id or "global".
 * - `aiPending`: keys (question id or "global") with a queued/running analysis.
 */
final class AiReportProps
{
    public static function for(Assessment $assessment): array
    {
        return [
            'ai' => ['configured' => (bool) config('assessments.anthropic.api_key')],
            'analyses' => (object) AiStore::latest($assessment->id),
            'aiPending' => [],
        ];
    }
}
