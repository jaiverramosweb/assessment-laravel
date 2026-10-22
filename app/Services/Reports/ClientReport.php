<?php

namespace App\Services\Reports;

use App\Models\Assessment;

/**
 * General report of a client (port of src/lib/server/services/client-report.ts): consolidates all
 * of its assessments (except drafts). As each assessment may have different questions, they are
 * compared assessment by assessment (and by area and cargo), not question by question. Only final
 * submissions count.
 */
final class ClientReport
{
    public const NO_DATA = 'Sin dato';

    public static function mean(array $xs): ?float
    {
        return $xs ? array_sum($xs) / count($xs) : null;
    }

    public static function build(string $clientId): array
    {
        $list = Assessment::query()
            ->where('client_id', $clientId)
            ->where('status', '!=', 'borrador')
            ->orderBy('starts_at')
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        $emails = [];
        $allScores = [];
        // group → assessment → scores of the people who submitted
        $areas = [];
        $cargos = [];

        foreach ($list as $a) {
            $report = Reports::build($a->id);
            $scores = Reports::participantScores($a->id);
            $own = [];
            foreach ($report['participants'] as $p) {
                $emails[mb_strtolower($p['email'])] = true;
                $rate = $scores[$p['id']]['rate'] ?? null;
                if ($p['status'] !== 'completado' || $rate === null) {
                    continue;
                }
                $own[] = $rate;
                $allScores[] = $rate;
                $areas[self::groupName($p['area'])][$a->id][] = $rate;
                $cargos[self::groupName($p['cargo'])][$a->id][] = $rate;
            }
            $rows[] = [
                'id' => $a->id,
                'title' => $a->title,
                'status' => $a->status,
                'startsAt' => $a->starts_at,
                'endsAt' => $a->ends_at,
                'timezone' => $a->timezone,
                'participants' => $report['summary']['invited'],
                'submitted' => $report['summary']['submitted'],
                'participationRate' => $report['summary']['participationRate'],
                'averageAlignment' => $report['summary']['averageAlignment'],
                'averageScore' => self::mean($own),
            ];
        }

        $groupRows = function (array $groups) use ($rows): array {
            $out = [];
            foreach ($groups as $name => $byA) {
                $all = array_merge(...array_values($byA));
                $byAssessment = [];
                foreach ($rows as $r) {
                    $byAssessment[$r['id']] = self::mean($byA[$r['id']] ?? []);
                }
                $out[] = [
                    'name' => (string) $name,
                    'submissions' => count($all),
                    'averageScore' => self::mean($all),
                    'byAssessment' => $byAssessment,
                ];
            }
            // "Sin dato" last; the rest by number of submissions.
            usort($out, fn ($x, $y) => ((int) ($x['name'] === self::NO_DATA) <=> (int) ($y['name'] === self::NO_DATA))
                ?: ($y['submissions'] <=> $x['submissions'])
                ?: Format::compare($x['name'], $y['name']));

            return $out;
        };

        $participants = array_sum(array_column($rows, 'participants'));
        $submitted = array_sum(array_column($rows, 'submitted'));
        $withSubmissions = array_values(array_filter($rows, fn ($r) => $r['submitted'] > 0));

        return [
            'summary' => [
                'assessments' => count($rows),
                'withSubmissions' => count($withSubmissions),
                'people' => count($emails),
                'participants' => $participants,
                'submitted' => $submitted,
                'participationRate' => $participants ? $submitted / $participants : null,
                'averageAlignment' => self::mean(array_values(array_filter(
                    array_column($withSubmissions, 'averageAlignment'),
                    fn ($x) => $x !== null,
                ))),
                'averageScore' => self::mean($allScores),
            ],
            'assessments' => $rows,
            'byArea' => $groupRows($areas),
            'byCargo' => $groupRows($cargos),
        ];
    }

    public static function groupName(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : self::NO_DATA;
    }
}
