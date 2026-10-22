<?php

namespace App\Services\Assessments;

use App\Models\Assessment;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Security\Audit;
use App\Services\Shared\AssessmentContext;
use Illuminate\Support\Facades\DB;

/** Questions of an assessment (port of src/lib/server/services/questions.ts). */
final class Questions
{
    /**
     * Questions with their options, including the correct one: ONLY for the admin panel.
     *
     * @return array<int, array{id: string, type: string, prompt: string, required: bool, position: int, options: array}>
     */
    public static function listForAdmin(string $assessmentId): array
    {
        $questions = Question::where('assessment_id', $assessmentId)->orderBy('position')->get();
        if ($questions->isEmpty()) {
            return [];
        }
        $options = QuestionOption::whereIn('question_id', $questions->pluck('id'))
            ->orderBy('position')
            ->get()
            ->groupBy('question_id');

        return $questions->map(fn (Question $q) => [
            'id' => $q->id,
            'type' => $q->type,
            'prompt' => $q->prompt,
            'required' => (bool) $q->required,
            'position' => (int) $q->position,
            'options' => ($options[$q->id] ?? collect())->map(fn (QuestionOption $o) => [
                'id' => $o->id,
                'text' => $o->text,
                'isCorrect' => (bool) $o->is_correct,
                'position' => (int) $o->position,
            ])->values()->all(),
        ])->all();
    }

    /** Converts stored questions to the input format (to append when importing). */
    public static function toInputs(array $list): array
    {
        return array_map(fn (array $q) => $q['type'] === 'opcion_unica'
            ? [
                'type' => 'opcion_unica',
                'prompt' => $q['prompt'],
                'required' => $q['required'],
                'options' => array_map(fn ($o) => ['text' => $o['text'], 'isCorrect' => $o['isCorrect']], $q['options']),
            ]
            : ['type' => 'abierta', 'prompt' => $q['prompt'], 'required' => $q['required']], $list);
    }

    /**
     * Replaces every question of the assessment (in a transaction). Only allowed while nobody
     * started answering and the assessment is not part of a series.
     *
     * @throws QuestionsLockedError
     */
    public static function replace(
        string $assessmentId,
        array $list,
        ?string $adminId,
        string $action = 'preguntas.editadas',
        ?string $ip = null,
    ): void {
        DB::transaction(function () use ($assessmentId, $list, $adminId, $action, $ip) {
            // Checked inside the transaction so a response cannot slip in between. On MySQL the
            // assessment row lock waits for answer saves in progress (they hold a shared lock).
            Assessment::whereKey($assessmentId)->lockForUpdate()->first(['id']);
            $locked = AssessmentContext::questionsLockReason($assessmentId);
            if ($locked) {
                throw new QuestionsLockedError($locked);
            }

            Question::where('assessment_id', $assessmentId)->delete();

            foreach (array_values($list) as $i => $q) {
                $question = Question::create([
                    'assessment_id' => $assessmentId,
                    'type' => $q['type'],
                    'prompt' => $q['prompt'],
                    'required' => $q['required'],
                    'position' => $i + 1,
                ]);
                if ($q['type'] === 'opcion_unica') {
                    foreach (array_values($q['options']) as $j => $o) {
                        QuestionOption::create([
                            'question_id' => $question->id,
                            'text' => $o['text'],
                            'is_correct' => $o['isCorrect'],
                            'position' => $j + 1,
                        ]);
                    }
                }
            }

            $choice = count(array_filter($list, fn ($q) => $q['type'] === 'opcion_unica'));
            Audit::log($adminId, $action, 'assessment', $assessmentId, [
                'preguntas' => count($list),
                'seleccion' => $choice,
                'abiertas' => count($list) - $choice,
            ], $ip);
        });
    }
}
