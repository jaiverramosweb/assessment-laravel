<?php

namespace App\Services\ParticipantFlow;

use App\Auth\ParticipantSessions;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Participant answers: drafts and the single final submission (port of services/answers.ts). */
final class Answers
{
    /**
     * Questions for the participant's browser, WITHOUT the correct option. Columns are selected
     * explicitly: `is_correct` is never read here, so it cannot leak by mistake.
     *
     * @return list<array{id: string, type: string, prompt: string, required: bool, options: list<array{id: string, text: string}>}>
     */
    public static function questionsFor(string $assessmentId): array
    {
        $qs = DB::table('questions')
            ->where('assessment_id', $assessmentId)
            ->orderBy('position')
            ->get(['id', 'type', 'prompt', 'required']);
        if ($qs->isEmpty()) {
            return [];
        }

        $options = DB::table('question_options')
            ->whereIn('question_id', $qs->pluck('id'))
            ->orderBy('position')
            ->get(['id', 'question_id', 'text'])
            ->groupBy('question_id');

        return $qs->map(fn ($q) => [
            'id' => $q->id,
            'type' => $q->type,
            'prompt' => $q->prompt,
            'required' => (bool) $q->required,
            'options' => ($options[$q->id] ?? collect())
                ->map(fn ($o) => ['id' => $o->id, 'text' => $o->text])->values()->all(),
        ])->all();
    }

    /** @return array<string, array{optionId: ?string, text: ?string}> saved answers by question id */
    public static function savedFor(string $submissionId): array
    {
        return DB::table('answers')
            ->where('submission_id', $submissionId)
            ->get(['question_id', 'option_id', 'text'])
            ->mapWithKeys(fn ($r) => [$r->question_id => ['optionId' => $r->option_id, 'text' => $r->text]])
            ->all();
    }

    /**
     * Answer fields of the form: `q_<questionId>` => value. The raw JSON body is read when
     * present so the global TrimStrings / ConvertEmptyStringsToNull middleware cannot alter
     * the typed text (the original kept spaces and only trimmed to detect empty answers).
     *
     * @return list<array{questionId: string, value: string}>
     */
    public static function readFields(Request $request): array
    {
        $data = $request->isJson() ? json_decode($request->getContent(), true) : null;
        if (! is_array($data)) {
            $data = $request->request->all();
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'q_') && (is_string($value) || $value === null)) {
                $out[] = ['questionId' => substr($key, 2), 'value' => $value ?? ''];
            }
        }

        return $out;
    }

    /**
     * Saves (or deletes, when empty) the sent answers. Validates that each question belongs to
     * this assessment, that the option belongs to the question and the text length.
     *
     * @param  list<array{questionId: string, value: string}>  $entries
     * @return array{ok: true, saved: int}|array{ok: false, errors: list<string>}
     */
    public static function save(array $ctx, array $entries, ?int $now = null): array
    {
        $now ??= Clock::nowMs();
        $byId = [];
        foreach (self::questionsFor($ctx['assessment']->id) as $i => $q) {
            $byId[$q['id']] = [...$q, 'number' => $i + 1];
        }
        $max = (int) $ctx['assessment']->open_max_chars;
        $errors = [];
        $ops = [];

        foreach ($entries as ['questionId' => $questionId, 'value' => $value]) {
            $q = $byId[$questionId] ?? null;
            if (! $q) {
                $errors[] = 'Se recibió una respuesta para una pregunta que no existe.';

                continue;
            }
            if ($q['type'] === 'opcion_unica') {
                if ($value === '') {
                    $ops[] = ['questionId' => $questionId, 'optionId' => null, 'text' => null];
                } elseif (in_array($value, array_column($q['options'], 'id'), true)) {
                    $ops[] = ['questionId' => $questionId, 'optionId' => $value, 'text' => null];
                } else {
                    $errors[] = "Pregunta {$q['number']}: opción no válida.";
                }
            } else {
                $text = str_replace("\r\n", "\n", $value);
                if (self::jsLength($text) > $max) {
                    $errors[] = "Pregunta {$q['number']}: supera el máximo de ".number_format($max, 0, ',', '.').' caracteres.';
                } else {
                    $ops[] = ['questionId' => $questionId, 'optionId' => null, 'text' => self::isBlank($text) ? null : $text];
                }
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }

        $submissionId = $ctx['submission']->id;
        DB::transaction(function () use ($ops, $submissionId, $now) {
            // Checked again inside the transaction: if it was delivered meanwhile, nothing is saved.
            $submission = DB::table('submissions')->where('id', $submissionId)->first(['submitted_at']);
            if (! $submission || $submission->submitted_at) {
                throw new AlreadySubmittedException;
            }

            foreach ($ops as $op) {
                if ($op['optionId'] === null && $op['text'] === null) {
                    DB::table('answers')
                        ->where('submission_id', $submissionId)
                        ->where('question_id', $op['questionId'])
                        ->delete();
                } else {
                    DB::table('answers')->upsert(
                        [[
                            'id' => (string) Str::uuid(),
                            'submission_id' => $submissionId,
                            'question_id' => $op['questionId'],
                            'option_id' => $op['optionId'],
                            'text' => $op['text'],
                            'updated_at' => $now,
                        ]],
                        ['submission_id', 'question_id'],
                        ['option_id', 'text', 'updated_at'],
                    );
                }
            }
            DB::table('submissions')->where('id', $submissionId)->update(['updated_at' => $now]);
        });

        return ['ok' => true, 'saved' => count($ops)];
    }

    /**
     * Final submission. Requires the mandatory questions and marks the submission only once
     * (the `submitted_at IS NULL` condition prevents a simultaneous double submission).
     *
     * @return array{ok: true}|array{ok: false, missing: list<int>}
     */
    public static function submit(array $ctx, ?int $now = null): array
    {
        $now ??= Clock::nowMs();
        $submissionId = $ctx['submission']->id;

        $result = DB::transaction(function () use ($ctx, $submissionId, $now) {
            $saved = self::savedFor($submissionId);
            $missing = [];
            foreach (self::questionsFor($ctx['assessment']->id) as $i => $q) {
                $a = $saved[$q['id']] ?? null;
                if ($q['required'] && ! ($a['optionId'] ?? null) && self::isBlank($a['text'] ?? '')) {
                    $missing[] = $i + 1;
                }
            }
            if ($missing) {
                return ['ok' => false, 'missing' => $missing];
            }

            $updated = DB::table('submissions')
                ->where('id', $submissionId)
                ->whereNull('submitted_at')
                ->update(['submitted_at' => $now, 'updated_at' => $now]);
            if ($updated === 0) {
                throw new AlreadySubmittedException;
            }

            return ['ok' => true];
        });

        // After submitting, the session is useless: it is closed.
        if ($result['ok']) {
            ParticipantSessions::invalidateAll($ctx['participant']['id']);
        }

        return $result;
    }

    /** Length as the browser counts it (UTF-16 code units), like the original `text.length`. */
    private static function jsLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** Like JavaScript's `text.trim() === ''` (Unicode whitespace included). */
    private static function isBlank(?string $text): bool
    {
        return $text === null || preg_match('/^[\s\x{00A0}\x{FEFF}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]*$/u', $text) === 1;
    }
}
