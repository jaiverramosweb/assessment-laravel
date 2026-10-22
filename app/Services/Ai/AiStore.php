<?php

namespace App\Services\Ai;

use App\Models\AiAnalysis;
use App\Support\InsertOrder;

/** Read side of the stored AI analyses (used by the reports page and the exports). */
final class AiStore
{
    public const FREQUENCIES = ['alta', 'media', 'baja'];

    /**
     * Latest stored analysis per question (key = question id) and the global one (key "global").
     * Records that do not match the current result shape are ignored.
     *
     * @return array<string, array{id: string, questionId: ?string, model: string, createdAt: int, responseCount: int, inputHash: string, result: array}>
     */
    public static function latest(string $assessmentId): array
    {
        $out = [];
        $rows = AiAnalysis::where('assessment_id', $assessmentId)
            ->orderByDesc('created_at')
            ->orderByDesc(InsertOrder::column())
            ->get();
        foreach ($rows as $r) {
            $key = $r->question_id ?? 'global';
            if (isset($out[$key]) || ! self::isValidResult($r->result)) {
                continue;
            }
            $out[$key] = [
                'id' => $r->id,
                'questionId' => $r->question_id,
                'model' => $r->model,
                'createdAt' => $r->created_at,
                'responseCount' => $r->response_count,
                'inputHash' => $r->input_hash,
                'result' => $r->result,
            ];
        }

        return $out;
    }

    /** Same rules as `aiAnalysisSchema` in resources/js/lib/ai-analysis.ts. */
    public static function isValidResult(mixed $r): bool
    {
        if (! is_array($r) || ! is_string($r['summary'] ?? null)) {
            return false;
        }
        $len = mb_strlen($r['summary']);
        if ($len < 1 || $len > 4000) {
            return false;
        }
        foreach (['themes' => 15, 'highlights' => 15, 'concerns' => 15, 'quotes' => 12] as $key => $max) {
            if (! is_array($r[$key] ?? null) || ! array_is_list($r[$key]) || count($r[$key]) > $max) {
                return false;
            }
        }
        foreach ($r['themes'] as $t) {
            if (! is_array($t)
                || ! is_string($t['name'] ?? null) || mb_strlen($t['name']) < 1 || mb_strlen($t['name']) > 200
                || ! is_string($t['description'] ?? null) || mb_strlen($t['description']) > 1000
                || ! in_array($t['frequency'] ?? null, self::FREQUENCIES, true)
                || ! is_int($t['approxCount'] ?? null) || $t['approxCount'] < 0) {
                return false;
            }
        }
        foreach (['highlights' => 600, 'concerns' => 600, 'quotes' => 400] as $key => $maxLen) {
            foreach ($r[$key] as $s) {
                if (! is_string($s) || mb_strlen($s) > $maxLen) {
                    return false;
                }
            }
        }

        return true;
    }
}
