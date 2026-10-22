<?php

namespace App\Services\Reports;

use App\Services\Assessments\Questions;
use App\Services\Participants\Participants;
use Illuminate\Support\Facades\DB;

/**
 * Report of an assessment (port of src/lib/server/services/reports.ts). There is no score per
 * person: each choice question has an "expected" option and the report measures how aligned the
 * answers are with it. Only final submissions count (drafts in progress are left out).
 */
final class Reports
{
    private static function ratio(int|float $a, int|float $b): ?float
    {
        return $b > 0 ? $a / $b : null;
    }

    /**
     * Report filters (?area=…&cargo=…). Only values that exist among the participants of the
     * assessment are accepted; any other value is ignored.
     *
     * @return array{filters: array{area: ?string, cargo: ?string}, areas: list<string>, cargos: list<string>}
     */
    public static function filtersFromQuery(string $assessmentId, mixed $area, mixed $cargo): array
    {
        ['areas' => $areas, 'cargos' => $cargos] = Participants::distinctAreasAndCargos($assessmentId);

        return [
            'filters' => [
                'area' => is_string($area) && $area !== '' && in_array($area, $areas, true) ? $area : null,
                'cargo' => is_string($cargo) && $cargo !== '' && in_array($cargo, $cargos, true) ? $cargo : null,
            ],
            'areas' => $areas,
            'cargos' => $cargos,
        ];
    }

    /** Final-submission answers of an assessment: participantId, questionId, optionId, text. */
    public static function submittedAnswers(string $assessmentId): array
    {
        return DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->where('submissions.assessment_id', $assessmentId)
            ->whereNotNull('submissions.submitted_at')
            ->get(['submissions.participant_id', 'answers.question_id', 'answers.option_id', 'answers.text'])
            ->map(fn ($r) => [
                'participantId' => $r->participant_id,
                'questionId' => $r->question_id,
                'optionId' => $r->option_id,
                'text' => $r->text,
            ])
            ->all();
    }

    /**
     * Score per participant who submitted (participantId → score): choice questions answered with
     * the expected option / the ones answered. Unanswered (optional) questions do not count.
     *
     * @return array<string, array{correct: int, answered: int, rate: ?float}>
     */
    public static function participantScores(string $assessmentId): array
    {
        $expected = [];
        foreach (Questions::listForAdmin($assessmentId) as $q) {
            foreach ($q['options'] as $o) {
                if ($o['isCorrect']) {
                    $expected[$o['id']] = true;
                }
            }
        }

        $scores = [];
        foreach (self::submittedAnswers($assessmentId) as $r) {
            if (! $r['optionId']) {
                continue;
            }
            $s = $scores[$r['participantId']] ?? ['correct' => 0, 'answered' => 0, 'rate' => null];
            $s['answered']++;
            if (isset($expected[$r['optionId']])) {
                $s['correct']++;
            }
            $s['rate'] = $s['correct'] / $s['answered'];
            $scores[$r['participantId']] = $s;
        }

        return $scores;
    }

    /**
     * @param  array{area?: ?string, cargo?: ?string}  $filters
     */
    public static function build(string $assessmentId, array $filters = []): array
    {
        $filters = ['area' => $filters['area'] ?? null, 'cargo' => $filters['cargo'] ?? null];
        $participants = array_values(array_filter(
            Participants::listParticipants($assessmentId),
            fn ($p) => (! $filters['area'] || $p['area'] === $filters['area'])
                && (! $filters['cargo'] || $p['cargo'] === $filters['cargo']),
        ));
        $submitted = array_values(array_filter($participants, fn ($p) => $p['status'] === 'completado'));
        $byId = array_column($submitted, null, 'id');

        // Answers of the final submissions, limited to the participants of the filter.
        $rows = array_values(array_filter(self::submittedAnswers($assessmentId), fn ($r) => isset($byId[$r['participantId']])));

        $questions = Questions::listForAdmin($assessmentId);
        $choice = [];
        $open = [];

        foreach (array_values($questions) as $i => $q) {
            $qRows = array_filter($rows, fn ($r) => $r['questionId'] === $q['id']);
            if ($q['type'] === 'opcion_unica') {
                $counts = [];
                foreach ($qRows as $r) {
                    if ($r['optionId']) {
                        $counts[$r['optionId']] = ($counts[$r['optionId']] ?? 0) + 1;
                    }
                }
                $respondents = array_sum($counts);
                $expected = null;
                foreach ($q['options'] as $o) {
                    if ($o['isCorrect']) {
                        $expected = $o;
                        break;
                    }
                }
                $aligned = $expected ? ($counts[$expected['id']] ?? 0) : 0;
                $choice[] = [
                    'id' => $q['id'],
                    'number' => $i + 1,
                    'prompt' => $q['prompt'],
                    'required' => $q['required'],
                    'respondents' => $respondents,
                    'omitted' => count($submitted) - $respondents,
                    'aligned' => $aligned,
                    'alignmentRate' => self::ratio($aligned, $respondents),
                    'options' => array_map(fn ($o) => [
                        'id' => $o['id'],
                        'text' => $o['text'],
                        'isExpected' => $o['isCorrect'],
                        'count' => $counts[$o['id']] ?? 0,
                        'rate' => self::ratio($counts[$o['id']] ?? 0, $respondents) ?? 0,
                    ], $q['options']),
                ];
            } else {
                $responses = [];
                foreach ($qRows as $r) {
                    if (trim((string) $r['text']) === '') {
                        continue;
                    }
                    $p = $byId[$r['participantId']];
                    $responses[] = [
                        'participantName' => $p['name'],
                        'email' => $p['email'],
                        'area' => $p['area'],
                        'cargo' => $p['cargo'],
                        'text' => $r['text'],
                    ];
                }
                usort($responses, fn ($a, $b) => Format::compare($a['participantName'], $b['participantName']));
                $open[] = [
                    'id' => $q['id'],
                    'number' => $i + 1,
                    'prompt' => $q['prompt'],
                    'required' => $q['required'],
                    'responses' => $responses,
                ];
            }
        }

        $optionText = [];
        foreach ($questions as $q) {
            foreach ($q['options'] as $o) {
                $optionText[$o['id']] = $o['text'];
            }
        }
        $answersByParticipant = [];
        foreach ($rows as $r) {
            $value = $r['optionId'] ? ($optionText[$r['optionId']] ?? null) : $r['text'];
            if ($value === null || $value === '') {
                continue;
            }
            $answersByParticipant[$r['participantId']][$r['questionId']] = $value;
        }

        $answered = array_values(array_filter($choice, fn ($c) => $c['alignmentRate'] !== null));
        $sorted = $answered;
        usort($sorted, fn ($a, $b) => ($b['alignmentRate'] <=> $a['alignmentRate']) ?: ($a['number'] <=> $b['number']));
        $top = min(3, intdiv(count($sorted), 2) ?: count($sorted));

        return [
            'filters' => $filters,
            'summary' => [
                'invited' => count($participants),
                'inProgress' => count(array_filter($participants, fn ($p) => $p['status'] === 'en_progreso')),
                'submitted' => count($submitted),
                'participationRate' => self::ratio(count($submitted), count($participants)),
                'averageAlignment' => $answered
                    ? array_sum(array_column($answered, 'alignmentRate')) / count($answered)
                    : null,
                'mostAligned' => array_slice($sorted, 0, $top),
                'leastAligned' => count($sorted) > 1 ? array_reverse(array_slice($sorted, -$top)) : [],
            ],
            'choice' => $choice,
            'open' => $open,
            'participants' => $participants,
            'answersByParticipant' => $answersByParticipant,
        ];
    }
}
