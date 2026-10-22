<?php

namespace App\Services\Participants;

use App\Models\EmailLog;
use App\Models\Participant;
use App\Models\Submission;
use App\Security\Audit;
use App\Support\Clock;
use App\Support\InsertOrder;
use Illuminate\Support\Facades\DB;

/**
 * Participants of an assessment: listing with status, manual add, bulk upsert, delete,
 * filter values and email results. Also used by the reports.
 */
final class Participants
{
    public const STATUSES = ['pendiente', 'en_progreso', 'completado'];

    /** Status from the submission timestamps (ms). */
    public static function statusOf(?int $startedAt, ?int $submittedAt): string
    {
        if ($submittedAt) {
            return 'completado';
        }
        if ($startedAt) {
            return 'en_progreso';
        }

        return 'pendiente';
    }

    /**
     * Participants of the assessment (by name) with their status and the result of the last email.
     *
     * @return list<array{id: string, name: string, email: string, area: ?string, cargo: ?string, source: string,
     *     invitedAt: ?int, startedAt: ?int, submittedAt: ?int, status: string, lastEmailStatus: ?string, lastEmailError: ?string}>
     */
    public static function listParticipants(string $assessmentId): array
    {
        $rows = DB::table('participants')
            ->leftJoin('submissions', 'submissions.participant_id', '=', 'participants.id')
            ->where('participants.assessment_id', $assessmentId)
            ->orderBy('participants.name')
            ->get([
                'participants.id', 'participants.name', 'participants.email', 'participants.area',
                'participants.cargo', 'participants.source', 'participants.invited_at',
                'submissions.started_at', 'submissions.submitted_at',
            ]);

        // Last email per participant (one query for all).
        $lastEmails = [];
        if ($rows->isNotEmpty()) {
            $logs = DB::table('email_log')
                ->join('participants', 'email_log.participant_id', '=', 'participants.id')
                ->where('participants.assessment_id', $assessmentId)
                ->orderByDesc('email_log.created_at')
                ->orderByDesc(InsertOrder::column('email_log'))
                ->get(['email_log.participant_id', 'email_log.status', 'email_log.error']);
            foreach ($logs as $log) {
                $lastEmails[$log->participant_id] ??= $log;
            }
        }

        return $rows->map(function ($r) use ($lastEmails) {
            $startedAt = $r->started_at === null ? null : (int) $r->started_at;
            $submittedAt = $r->submitted_at === null ? null : (int) $r->submitted_at;
            $last = $lastEmails[$r->id] ?? null;

            return [
                'id' => $r->id,
                'name' => $r->name,
                'email' => $r->email,
                'area' => $r->area,
                'cargo' => $r->cargo,
                'source' => $r->source,
                'invitedAt' => $r->invited_at === null ? null : (int) $r->invited_at,
                'startedAt' => $startedAt,
                'submittedAt' => $submittedAt,
                'status' => self::statusOf($startedAt, $submittedAt),
                'lastEmailStatus' => $last?->status,
                'lastEmailError' => $last?->error,
            ];
        })->values()->all();
    }

    /** Adds one participant (validated input from ParticipantInput). */
    public static function add(string $assessmentId, array $input, string $adminId, ?string $ip = null): Participant
    {
        $exists = Participant::where('assessment_id', $assessmentId)->where('email', $input['email'])->exists();
        if ($exists) {
            throw new ParticipantExistsError($input['email']);
        }

        $created = Participant::create([...$input, 'assessment_id' => $assessmentId, 'source' => 'cargado']);
        Audit::log($adminId, 'participantes.cargados', 'assessment', $assessmentId, ['nuevos' => 1, 'email' => $input['email']], $ip);

        return $created;
    }

    /**
     * Bulk load: creates new emails and updates name/area/cargo/extra of existing ones.
     * All in one transaction.
     *
     * @return array{created: int, updated: int}
     */
    public static function upsert(string $assessmentId, array $list, string $adminId, ?string $ip = null): array
    {
        return DB::transaction(function () use ($assessmentId, $list, $adminId, $ip) {
            // Two simultaneous imports run one after the other (MySQL; SQLite serializes writes).
            DB::table('assessments')->where('id', $assessmentId)->lockForUpdate()->first(['id']);
            $existing = Participant::where('assessment_id', $assessmentId)->get()->keyBy('email');

            $created = 0;
            $updated = 0;
            foreach ($list as $p) {
                $current = $existing->get($p['email']);
                if ($current) {
                    $current->fill(['name' => $p['name'], 'area' => $p['area'], 'cargo' => $p['cargo'], 'extra' => $p['extra']])->save();
                    $updated++;
                } else {
                    $existing->put($p['email'], Participant::create([...$p, 'assessment_id' => $assessmentId, 'source' => 'cargado']));
                    $created++;
                }
            }

            Audit::log($adminId, 'participantes.cargados', 'assessment', $assessmentId, ['nuevos' => $created, 'actualizados' => $updated], $ip);

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * Deletes a participant that has not started answering. False when it does not belong to
     * the assessment.
     *
     * @throws ParticipantHasResponsesError
     */
    public static function delete(string $assessmentId, string $participantId, string $adminId, ?string $ip = null): bool
    {
        return DB::transaction(function () use ($assessmentId, $participantId, $adminId, $ip) {
            $p = Participant::where('id', $participantId)->where('assessment_id', $assessmentId)->first();
            if (! $p) {
                return false;
            }
            if (Submission::where('participant_id', $p->id)->exists()) {
                throw new ParticipantHasResponsesError;
            }

            $p->delete();
            Audit::log($adminId, 'participantes.eliminado', 'assessment', $assessmentId, ['email' => $p->email], $ip);

            return true;
        });
    }

    /**
     * Distinct area and cargo values, for the filters.
     *
     * @return array{areas: list<string>, cargos: list<string>}
     */
    public static function distinctAreasAndCargos(string $assessmentId): array
    {
        $pick = fn (string $column) => Participant::where('assessment_id', $assessmentId)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();

        return ['areas' => $pick('area'), 'cargos' => $pick('cargo')];
    }

    /**
     * Marks as invited and records the result of each email.
     *
     * @param  list<array{participantId: string, ok: bool, error?: string}>  $results
     */
    public static function recordEmailResults(array $results, string $adminId, ?int $now = null): void
    {
        $now ??= Clock::nowMs();
        DB::transaction(function () use ($results, $adminId, $now) {
            foreach ($results as $r) {
                EmailLog::create([
                    'participant_id' => $r['participantId'],
                    'status' => $r['ok'] ? 'enviado' : 'error',
                    'error' => $r['ok'] ? null : mb_substr($r['error'] ?? 'Error desconocido', 0, 500),
                    'sent_by_id' => $adminId,
                    'created_at' => $now,
                ]);
            }
            $okIds = array_column(array_filter($results, fn ($r) => $r['ok']), 'participantId');
            if ($okIds) {
                Participant::whereIn('id', $okIds)->update(['invited_at' => $now]);
            }
        });
    }

    /**
     * Totals by status plus how many were never invited.
     *
     * @return array{total: int, pendiente: int, en_progreso: int, completado: int, sinInvitar: int}
     */
    public static function counts(array $list): array
    {
        $c = ['total' => count($list), 'pendiente' => 0, 'en_progreso' => 0, 'completado' => 0, 'sinInvitar' => 0];
        foreach ($list as $p) {
            $c[$p['status']]++;
            if (! $p['invitedAt']) {
                $c['sinInvitar']++;
            }
        }

        return $c;
    }
}
