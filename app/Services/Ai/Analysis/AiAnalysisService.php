<?php

namespace App\Services\Ai\Analysis;

use App\Models\AiAnalysis;
use App\Models\Assessment;
use App\Models\Participant;
use App\Security\Audit;
use App\Services\Ai\AiStore;
use App\Services\Assessments\Questions;
use Illuminate\Support\Facades\DB;

/**
 * AI analysis of open answers (port of src/lib/server/services/ai-analysis.ts).
 * Final submissions only, without filters.
 * - Answers are anonymized before they are sent.
 * - Long texts are processed in blocks and then consolidated.
 * - The result is stored with its model and date; when the answers did not change, the last
 *   analysis is reused instead of calling the AI again (except with "Regenerar").
 * Running it at most once per key at a time is the job's responsibility (AnalyzeOpenAnswers).
 */
final class AiAnalysisService
{
    /** Maximum size (characters) of answers per call (~25 thousand tokens). */
    public const CHUNK_CHARS = 100_000;

    /**
     * Fails early (no AI call) and tells whether a stored analysis already covers the current
     * answers. Used by the web request before queueing.
     *
     * @return array{inputHash: string, cached: ?array}
     *
     * @throws AiError
     */
    public static function check(string $assessmentId, ?string $questionId, string $model): array
    {
        $a = self::assessment($assessmentId);
        if ($questionId === null) {
            $questions = self::globalQuestions($a);
            $hash = self::globalHash($model, $questions);
        } else {
            $q = self::openQuestion($a, $questionId);
            $hash = self::questionHash($model, $q);
        }
        $latest = AiStore::latest($a->id)[$questionId ?? 'global'] ?? null;

        return ['inputHash' => $hash, 'cached' => ($latest['inputHash'] ?? null) === $hash ? $latest : null];
    }

    /**
     * Analyzes one open question. With `$force` it calls the AI even when nothing changed.
     *
     * @return array{analysis: array, cached: bool}
     *
     * @throws AiError
     */
    public static function analyzeQuestion(
        Analyzer $analyzer,
        string $assessmentId,
        string $questionId,
        ?string $adminId,
        bool $force = false,
        ?string $ip = null,
    ): array {
        $a = self::assessment($assessmentId);
        $q = self::openQuestion($a, $questionId);
        $inputHash = self::questionHash($analyzer->model(), $q);
        $latest = AiStore::latest($a->id)[$q['id']] ?? null;
        if (! $force && ($latest['inputHash'] ?? null) === $inputHash) {
            return ['analysis' => $latest, 'cached' => true];
        }

        [$analysis, $calls] = self::runQuestion($analyzer, $a->title, $q);
        $stored = self::store($a->id, $q['id'], $analyzer->model(), $analysis, $inputHash, count($q['texts']), $adminId);
        Audit::log($adminId, 'ia.analisis', 'assessment', $a->id, [
            'pregunta' => $q['number'],
            'respuestas' => count($q['texts']),
            'modelo' => $analyzer->model(),
            'llamadas' => $calls,
        ], $ip);

        return ['analysis' => $stored, 'cached' => false];
    }

    /**
     * Global analysis of every open question. When all the text fits in one call it is sent
     * whole; otherwise it is consolidated from the analysis of each question.
     *
     * @return array{analysis: array, cached: bool}
     *
     * @throws AiError
     */
    public static function analyzeGlobal(
        Analyzer $analyzer,
        string $assessmentId,
        ?string $adminId,
        bool $force = false,
        ?string $ip = null,
    ): array {
        $a = self::assessment($assessmentId);
        $questions = self::globalQuestions($a);
        $total = array_sum(array_map(fn ($q) => count($q['texts']), $questions));
        $inputHash = self::globalHash($analyzer->model(), $questions);
        $latest = AiStore::latest($a->id)['global'] ?? null;
        if (! $force && ($latest['inputHash'] ?? null) === $inputHash) {
            return ['analysis' => $latest, 'cached' => true];
        }

        $chars = 0;
        foreach ($questions as $q) {
            foreach ($q['texts'] as $t) {
                $chars += mb_strlen($t);
            }
        }
        $calls = 1;
        if ($chars <= self::CHUNK_CHARS) {
            $analysis = $analyzer->analyze(Prompts::global($a->title, array_map(
                fn ($q) => ['question' => $q['prompt'], 'responses' => $q['texts']],
                $questions
            )));
        } else {
            // Reuses (or generates) the analysis of each question and consolidates them.
            $partials = [];
            foreach ($questions as $q) {
                $r = self::analyzeQuestion($analyzer, $a->id, $q['id'], $adminId, false, $ip);
                if (! $r['cached']) {
                    $calls++;
                }
                $partials[] = ['label' => "pregunta {$q['number']}: {$q['prompt']}", 'analysis' => $r['analysis']['result']];
            }
            $analysis = $analyzer->analyze(Prompts::consolidation(
                $a->title, 'análisis global de todas las preguntas abiertas', $total, $partials
            ));
        }

        $stored = self::store($a->id, null, $analyzer->model(), $analysis, $inputHash, $total, $adminId);
        Audit::log($adminId, 'ia.analisis', 'assessment', $a->id, [
            'alcance' => 'global',
            'respuestas' => $total,
            'modelo' => $analyzer->model(),
            'llamadas' => $calls,
        ], $ip);

        return ['analysis' => $stored, 'cached' => false];
    }

    private static function assessment(string $assessmentId): Assessment
    {
        return Assessment::find($assessmentId) ?? throw new AiError('El assessment no existe.');
    }

    private static function openQuestion(Assessment $a, string $questionId): array
    {
        $q = collect(self::collect($a->id))->firstWhere('id', $questionId)
            ?? throw new AiError('La pregunta no existe o no es de respuesta abierta.');
        if (! $q['texts']) {
            throw new AiError('Esta pregunta todavía no tiene respuestas entregadas.');
        }

        return $q;
    }

    private static function globalQuestions(Assessment $a): array
    {
        $questions = array_values(array_filter(self::collect($a->id), fn ($q) => $q['texts']));
        if (! $questions) {
            throw new AiError('Todavía no hay respuestas abiertas entregadas.');
        }

        return $questions;
    }

    /**
     * Submitted open answers per question (anonymized, stable order) for every open question.
     *
     * @return array<int, array{id: string, number: int, prompt: string, texts: string[]}>
     */
    private static function collect(string $assessmentId): array
    {
        $questions = Questions::listForAdmin($assessmentId);
        $rows = DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->where('submissions.assessment_id', $assessmentId)
            ->whereNotNull('submissions.submitted_at')
            ->whereNotNull('answers.text')
            ->get(['answers.question_id', 'answers.text'])
            ->groupBy('question_id');
        $anonymizer = new Anonymizer(Participant::where('assessment_id', $assessmentId)->pluck('name')->all());

        $open = [];
        foreach ($questions as $i => $q) {
            if ($q['type'] !== 'abierta') {
                continue;
            }
            $texts = [];
            foreach ($rows[$q['id']] ?? [] as $r) {
                $text = AnalysisNormalizer::trimText((string) $r->text);
                if ($text !== '') {
                    $texts[] = $anonymizer->apply($text);
                }
            }
            // Stable order so the hash does not depend on the database order.
            sort($texts, SORT_STRING);
            $open[] = ['id' => $q['id'], 'number' => $i + 1, 'prompt' => $q['prompt'], 'texts' => $texts];
        }

        return $open;
    }

    /** One call, or blocks + consolidation when the text is long. Returns [analysis, calls]. */
    private static function runQuestion(Analyzer $analyzer, string $title, array $q): array
    {
        $chunks = Anonymizer::chunk($q['texts'], self::CHUNK_CHARS);
        if (count($chunks) === 1) {
            return [$analyzer->analyze(Prompts::question($title, $q['prompt'], $q['texts'])), 1];
        }
        $partials = [];
        foreach ($chunks as $i => $responses) {
            $partials[] = [
                'label' => 'bloque '.($i + 1),
                'analysis' => $analyzer->analyze(Prompts::question($title, $q['prompt'], $responses, [
                    'index' => $i + 1, 'total' => count($chunks), 'totalResponses' => count($q['texts']),
                ])),
            ];
        }
        $analysis = $analyzer->analyze(Prompts::consolidation(
            $title, "pregunta \"{$q['prompt']}\"", count($q['texts']), $partials
        ));

        return [$analysis, count($chunks) + 1];
    }

    private static function questionHash(string $model, array $q): string
    {
        return self::hash([$model, 'pregunta', $q['prompt'], ...$q['texts']]);
    }

    private static function globalHash(string $model, array $questions): string
    {
        $parts = [$model, 'global'];
        foreach ($questions as $q) {
            array_push($parts, $q['prompt'], ...$q['texts']);
        }

        return self::hash($parts);
    }

    private static function hash(array $parts): string
    {
        $ctx = hash_init('sha256');
        foreach ($parts as $p) {
            hash_update($ctx, $p."\0");
        }

        return hash_final($ctx);
    }

    private static function store(
        string $assessmentId,
        ?string $questionId,
        string $model,
        array $analysis,
        string $inputHash,
        int $responseCount,
        ?string $adminId,
    ): array {
        $row = AiAnalysis::create([
            'assessment_id' => $assessmentId,
            'question_id' => $questionId,
            'scope' => $questionId ? 'pregunta' : 'global',
            'model' => $model,
            'result' => $analysis,
            'input_hash' => $inputHash,
            'response_count' => $responseCount,
            'created_by_id' => $adminId,
        ]);

        return [
            'id' => $row->id,
            'questionId' => $row->question_id,
            'model' => $row->model,
            'createdAt' => (int) $row->created_at,
            'responseCount' => (int) $row->response_count,
            'inputHash' => $row->input_hash,
            'result' => $analysis,
        ];
    }
}
