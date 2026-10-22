<?php

namespace App\Services\Reports;

use App\Models\Assessment;
use App\Services\Assessments\Questions;
use App\Services\Participants\Participants;
use App\Services\Shared\AssessmentContext;
use Illuminate\Support\Collection;

/**
 * Comparison between the measurements of a series (port of src/lib/server/services/comparison.ts).
 * Questions are paired by position (they cannot be modified in a series) and people by email.
 * - "pareado": only who submitted ALL the compared measurements (fair comparison).
 * - "todos": each measurement with all of its submissions.
 */
final class Comparison
{
    public const MODES = ['pareado', 'todos'];

    /** Series members in order (only itself when it is not in a series). */
    public static function members(Assessment $a): Collection
    {
        return AssessmentContext::seriesMembers($a);
    }

    /**
     * Mode and chosen measurements from the raw query string (?modo=todos&m=<id>&m=<id>). The raw
     * string is parsed by hand because PHP keeps only the last of repeated keys. Foreign ids are
     * ignored.
     *
     * @param  list<string>  $seriesIds
     * @return array{mode: string, selectedIds: list<string>}
     */
    public static function params(string $queryString, array $seriesIds): array
    {
        $mode = 'pareado';
        $selected = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $value = urldecode($value);
            if ($key === 'modo' && $value === 'todos') {
                $mode = 'todos';
            } elseif ($key === 'modo') {
                $mode = 'pareado';
            } elseif (($key === 'm' || preg_match('/^m\[\d*\]$/', $key)) && in_array($value, $seriesIds, true)) {
                $selected[] = $value;
            }
        }

        return ['mode' => $mode, 'selectedIds' => $selected];
    }

    private static function delta(array $values): ?float
    {
        $known = array_values(array_filter($values, fn ($v) => $v !== null));

        return count($known) >= 2 ? $known[count($known) - 1] - $known[0] : null;
    }

    /** Submitted people, scores and picks of one measurement. */
    private static function load(Assessment $a): array
    {
        $questions = Questions::listForAdmin($a->id);
        $byQuestion = array_column($questions, null, 'id');
        $submitted = array_filter(Participants::listParticipants($a->id), fn ($p) => $p['status'] === 'completado');
        $byId = array_column($submitted, null, 'id');
        $scores = Reports::participantScores($a->id);

        $people = [];   // email → person who submitted
        $scoreByEmail = [];
        foreach ($submitted as $p) {
            $people[$p['email']] = ['participantId' => $p['id'], 'name' => $p['name'], 'area' => $p['area'], 'cargo' => $p['cargo']];
            $scoreByEmail[$p['email']] = $scores[$p['id']]['rate'] ?? null;
        }

        // email → question position → ['option' => position, 'correct' => bool] | ['text' => string]
        $picks = [];
        foreach (Reports::submittedAnswers($a->id) as $r) {
            $p = $byId[$r['participantId']] ?? null;
            $q = $byQuestion[$r['questionId']] ?? null;
            if (! $p || ! $q) {
                continue;
            }
            if ($r['optionId']) {
                foreach ($q['options'] as $o) {
                    if ($o['id'] === $r['optionId']) {
                        $picks[$p['email']][$q['position']] = ['option' => $o['position'], 'correct' => $o['isCorrect']];
                    }
                }
            } elseif (trim((string) $r['text']) !== '') {
                $picks[$p['email']][$q['position']] = ['text' => $r['text']];
            }
        }

        return ['a' => $a, 'people' => $people, 'scores' => $scoreByEmail, 'picks' => $picks, 'questions' => $questions];
    }

    /**
     * @param  list<string>  $selectedIds  measurements to compare (of the series); empty = every non-draft one.
     */
    public static function build(Assessment $assessment, string $mode = 'pareado', array $selectedIds = []): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'pareado';
        $all = self::members($assessment)->map(fn (Assessment $a) => self::load($a))->values()->all();
        $series = array_map(fn ($l, $i) => [
            'id' => $l['a']->id,
            'title' => $l['a']->title,
            'order' => $l['a']->series_order ?? $i + 1,
            'status' => $l['a']->status,
            'submitted' => count($l['people']),
        ], $all, array_keys($all));

        $chosen = $selectedIds
            ? array_values(array_filter($all, fn ($l) => in_array($l['a']->id, $selectedIds, true)))
            : array_values(array_filter($all, fn ($l) => $l['a']->status !== 'borrador'));
        $ms = $chosen ?: array_slice($all, 0, 1);

        // People included in each measurement according to the mode.
        $pairedEmails = array_values(array_filter(
            array_map('strval', array_keys($ms[0]['people'] ?? [])),
            fn ($e) => array_reduce($ms, fn ($ok, $l) => $ok && isset($l['people'][$e]), true),
        ));
        $included = array_map(fn ($l) => $mode === 'pareado'
            ? array_values(array_filter($pairedEmails, fn ($e) => isset($l['people'][$e])))
            : array_map('strval', array_keys($l['people'])), $ms);

        $base = $ms[0]['questions'] ?? [];
        $choicePicks = function (int $i, int $position) use ($ms, $included): array {
            $out = [];
            foreach ($included[$i] as $e) {
                $p = $ms[$i]['picks'][$e][$position] ?? null;
                if ($p && isset($p['option'])) {
                    $out[] = $p;
                }
            }

            return $out;
        };

        // ---- Choice questions ----
        $choice = [];
        foreach ($base as $q) {
            if ($q['type'] !== 'opcion_unica') {
                continue;
            }
            $respondents = [];
            $rates = [];
            foreach ($ms as $i => $l) {
                $picks = $choicePicks($i, $q['position']);
                $respondents[] = count($picks);
                $rates[] = $picks ? count(array_filter($picks, fn ($p) => $p['correct'])) / count($picks) : null;
            }
            $options = array_map(fn ($o) => [
                'text' => $o['text'],
                'isExpected' => $o['isCorrect'],
                'rates' => array_map(function ($i) use ($choicePicks, $q, $o) {
                    $picks = $choicePicks($i, $q['position']);

                    return $picks ? count(array_filter($picks, fn ($p) => $p['option'] === $o['position'])) / count($picks) : null;
                }, array_keys($ms)),
            ], $q['options']);

            $transitions = null;
            if ($mode === 'pareado' && count($ms) >= 2) {
                $transitions = ['improved' => 0, 'keptRight' => 0, 'worsened' => 0, 'keptWrong' => 0];
                $first = $ms[0];
                $last = $ms[count($ms) - 1];
                foreach ($pairedEmails as $e) {
                    $x = $first['picks'][$e][$q['position']] ?? null;
                    $y = $last['picks'][$e][$q['position']] ?? null;
                    if (! $x || ! $y || ! isset($x['option']) || ! isset($y['option'])) {
                        continue;
                    }
                    if (! $x['correct'] && $y['correct']) {
                        $transitions['improved']++;
                    } elseif ($x['correct'] && $y['correct']) {
                        $transitions['keptRight']++;
                    } elseif ($x['correct'] && ! $y['correct']) {
                        $transitions['worsened']++;
                    } else {
                        $transitions['keptWrong']++;
                    }
                }
            }
            $choice[] = [
                'position' => $q['position'],
                'prompt' => $q['prompt'],
                'rates' => $rates,
                'respondents' => $respondents,
                'delta' => self::delta($rates),
                'transitions' => $transitions,
                'options' => $options,
            ];
        }

        // ---- Open questions: texts side by side per person ----
        $everyone = array_values(array_unique(array_merge(...($included ?: [[]]))));
        $personOf = function (string $email) use ($ms): ?array {
            for ($i = count($ms) - 1; $i >= 0; $i--) {
                if (isset($ms[$i]['people'][$email])) {
                    return $ms[$i]['people'][$email];
                }
            }

            return null;
        };
        $byName = fn ($x, $y) => Format::compare($x['name'], $y['name']);

        $open = [];
        foreach ($base as $q) {
            if ($q['type'] !== 'abierta') {
                continue;
            }
            $responses = [];
            foreach ($everyone as $email) {
                $texts = [];
                foreach ($ms as $i => $l) {
                    $p = in_array($email, $included[$i], true) ? ($l['picks'][$email][$q['position']] ?? null) : null;
                    $texts[] = $p && isset($p['text']) ? $p['text'] : null;
                }
                if (array_filter($texts, fn ($t) => $t !== null)) {
                    $responses[] = ['email' => $email, 'name' => $personOf($email)['name'] ?? $email, 'texts' => $texts];
                }
            }
            usort($responses, $byName);
            $open[] = ['position' => $q['position'], 'prompt' => $q['prompt'], 'responses' => $responses];
        }

        // ---- People ----
        $people = [];
        foreach ($everyone as $email) {
            $p = $personOf($email);
            $scores = [];
            foreach ($ms as $i => $l) {
                $scores[] = in_array($email, $included[$i], true) ? ($l['scores'][$email] ?? null) : null;
            }
            $people[] = [
                'email' => $email,
                'name' => $p['name'],
                'area' => $p['area'],
                'cargo' => $p['cargo'],
                'scores' => $scores,
                'delta' => self::delta($scores),
            ];
        }
        usort($people, $byName);

        // ---- By area and cargo ----
        $groupBy = function (string $key) use ($people, $ms): array {
            $names = array_values(array_unique(array_map(fn ($p) => ClientReport::groupName($p[$key]), $people)));
            $out = [];
            foreach ($names as $name) {
                $members = array_filter($people, fn ($p) => ClientReport::groupName($p[$key]) === $name);
                $counts = [];
                $scores = [];
                foreach (array_keys($ms) as $i) {
                    $values = array_values(array_filter(array_map(fn ($p) => $p['scores'][$i], $members), fn ($s) => $s !== null));
                    $counts[] = count($values);
                    $scores[] = ClientReport::mean($values);
                }
                $out[] = ['name' => $name, 'counts' => $counts, 'scores' => $scores, 'delta' => self::delta($scores)];
            }
            usort($out, fn ($x, $y) => ((int) ($x['name'] === ClientReport::NO_DATA) <=> (int) ($y['name'] === ClientReport::NO_DATA))
                ?: (max($y['counts'] ?: [0]) <=> max($x['counts'] ?: [0]))
                ?: Format::compare($x['name'], $y['name']));

            return $out;
        };

        $measurements = [];
        foreach ($ms as $i => $l) {
            $scores = array_values(array_filter(array_map(fn ($e) => $l['scores'][$e] ?? null, $included[$i]), fn ($s) => $s !== null));
            $alignments = array_values(array_filter(array_map(fn ($c) => $c['rates'][$i], $choice), fn ($r) => $r !== null));
            $measurements[] = [
                'id' => $l['a']->id,
                'title' => $l['a']->title,
                'order' => $l['a']->series_order ?? $i + 1,
                'status' => $l['a']->status,
                'startsAt' => $l['a']->starts_at,
                'endsAt' => $l['a']->ends_at,
                'timezone' => $l['a']->timezone,
                'submitted' => count($included[$i]),
                'averageScore' => ClientReport::mean($scores),
                'averageAlignment' => ClientReport::mean($alignments),
            ];
        }

        $scoresPer = array_column($measurements, 'averageScore');
        $alignPer = array_column($measurements, 'averageAlignment');

        return [
            'mode' => $mode,
            'series' => $series,
            'measurements' => $measurements,
            'summary' => [
                'paired' => count($pairedEmails),
                'firstScore' => $scoresPer[0] ?? null,
                'lastScore' => $scoresPer ? $scoresPer[count($scoresPer) - 1] : null,
                'delta' => self::delta($scoresPer),
                'firstAlignment' => $alignPer[0] ?? null,
                'lastAlignment' => $alignPer ? $alignPer[count($alignPer) - 1] : null,
                'alignmentDelta' => self::delta($alignPer),
            ],
            'choice' => $choice,
            'open' => $open,
            'people' => $people,
            'byArea' => $groupBy('area'),
            'byCargo' => $groupBy('cargo'),
        ];
    }
}
