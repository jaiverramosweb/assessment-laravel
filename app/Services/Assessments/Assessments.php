<?php

namespace App\Services\Assessments;

use App\Auth\Passwords;
use App\Models\Assessment;
use App\Models\Client;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Security\Audit;
use App\Security\SecretBox;
use App\Services\Shared\AssessmentContext;
use App\Services\Shared\Brand;
use Illuminate\Support\Facades\DB;

/** Assessments (port of src/lib/server/services/assessments.ts). */
final class Assessments
{
    private const SLUG_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789'; // no ambiguous characters (l, o, 0, 1)

    private const SLUG_LENGTH = 12;

    /** Random slug for the public link. 32^12 ≈ 10^18 combinations: it cannot be guessed. */
    public static function generateSlug(): string
    {
        $out = '';
        for ($i = 0; $i < self::SLUG_LENGTH; $i++) {
            $out .= self::SLUG_ALPHABET[random_int(0, strlen(self::SLUG_ALPHABET) - 1)];
        }

        return $out;
    }

    /** Clients for the selectors, by name. */
    public static function clientOptions(): array
    {
        return Client::query()
            ->select(['id', 'name'])
            ->orderByRaw('lower(name)')
            ->get()
            ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    public static function clientExists(string $id): bool
    {
        return Client::whereKey($id)->exists();
    }

    /**
     * Panel list, with question, participant and submission counts, and its client.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listForDashboard(bool $includeArchived = false, ?string $clientId = null): array
    {
        $rows = DB::table('assessments')
            ->leftJoin('clients', 'clients.id', '=', 'assessments.client_id')
            ->when(! $includeArchived, fn ($q) => $q->where('assessments.status', '!=', 'archivado'))
            ->when($clientId, fn ($q) => $q->where('assessments.client_id', $clientId))
            ->orderByDesc('assessments.created_at')
            ->select([
                'assessments.id', 'assessments.client_id', 'clients.name as client_name',
                'assessments.title', 'assessments.status', 'assessments.starts_at', 'assessments.ends_at',
                'assessments.timezone', 'assessments.slug', 'assessments.access_password_hash',
                'clients.logo_mime', 'clients.logo_updated_at',
            ])
            ->selectRaw('(select count(*) from questions q where q.assessment_id = assessments.id) as question_count')
            ->selectRaw('(select count(*) from participants p where p.assessment_id = assessments.id) as participant_count')
            ->selectRaw('(select count(*) from submissions s where s.assessment_id = assessments.id and s.submitted_at is not null) as submitted_count')
            ->selectRaw('(select count(*) from submissions s where s.assessment_id = assessments.id and s.submitted_at is null) as in_progress_count')
            ->selectRaw('(select count(*) from participants p where p.assessment_id = assessments.id and p.invited_at is null) as not_invited_count')
            ->get();

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'clientId' => $r->client_id,
            'clientName' => $r->client_name,
            'title' => $r->title,
            'status' => $r->status,
            'startsAt' => (int) $r->starts_at,
            'endsAt' => (int) $r->ends_at,
            'timezone' => $r->timezone,
            'questionCount' => (int) $r->question_count,
            'participantCount' => (int) $r->participant_count,
            'submittedCount' => (int) $r->submitted_count,
            'inProgressCount' => (int) $r->in_progress_count,
            'notInvitedCount' => (int) $r->not_invited_count,
            'hasPassword' => $r->access_password_hash !== null,
            'slug' => $r->slug,
            'logoUrl' => Brand::logoUrl($r->slug, [
                'logo_mime' => $r->logo_mime,
                'logo_updated_at' => $r->logo_updated_at === null ? null : (int) $r->logo_updated_at,
            ]),
        ])->all();
    }

    /**
     * Average alignment of the submitted answers: mean, over the choice questions with answers,
     * of the share that chose the expected option (same as the report summary). Null without answers.
     */
    public static function averageAlignment(string $assessmentId): ?float
    {
        $rates = DB::table('questions as q')
            ->join('answers as a', 'a.question_id', '=', 'q.id')
            ->join('submissions as s', function ($join) {
                $join->on('s.id', '=', 'a.submission_id')->whereNotNull('s.submitted_at');
            })
            ->leftJoin('question_options as o', 'o.id', '=', 'a.option_id')
            ->where('q.assessment_id', $assessmentId)
            ->where('q.type', 'opcion_unica')
            ->whereNotNull('a.option_id')
            ->groupBy('q.id')
            ->selectRaw('count(*) as respondents, sum(case when o.is_correct = 1 then 1 else 0 end) as aligned')
            ->get()
            ->filter(fn ($r) => (int) $r->respondents > 0)
            ->map(fn ($r) => (int) $r->aligned / (int) $r->respondents);

        return $rates->isEmpty() ? null : (float) ($rates->sum() / $rates->count());
    }

    public static function create(array $input, string $adminId, ?string $ip = null): Assessment
    {
        $created = Assessment::create([...$input, 'slug' => self::generateSlug(), 'created_by_id' => $adminId]);
        Audit::log($adminId, 'assessment.creado', 'assessment', $created->id, ['titulo' => $created->title], $ip);

        return $created->fresh();
    }

    public static function update(string $id, array $input, string $adminId, ?string $ip = null): void
    {
        Assessment::findOrFail($id)->update($input);
        Audit::log($adminId, 'assessment.editado', 'assessment', $id, ['titulo' => $input['title']], $ip);
    }

    /** Sets or changes the shared password. Active participant sessions are not affected. */
    public static function setAccessPassword(string $id, string $password, string $adminId, ?string $ip = null): void
    {
        Assessment::findOrFail($id)->update([
            'access_password_hash' => Passwords::hash($password),
            'access_password_enc' => SecretBox::encrypt($password),
        ]);
        Audit::log($adminId, 'assessment.password_cambiada', 'assessment', $id, null, $ip);
    }

    /** Current access password in clear text, or null when only the hash exists (set before). */
    public static function readAccessPassword(Assessment $a): ?string
    {
        return $a->access_password_enc ? SecretBox::decrypt($a->access_password_enc) : null;
    }

    /** Shows the password to the admin and records it in the audit log. */
    public static function revealAccessPassword(Assessment $a, string $adminId, ?string $ip = null): ?string
    {
        $password = self::readAccessPassword($a);
        if ($password !== null) {
            Audit::log($adminId, 'assessment.password_vista', 'assessment', $a->id, null, $ip);
        }

        return $password;
    }

    /** Requirements to publish. Empty list = it can be published. */
    public static function publishProblems(Assessment $a): array
    {
        $problems = [];
        if (! $a->access_password_hash) {
            $problems[] = 'Defina la contraseña de acceso.';
        }
        if (AssessmentContext::countQuestions($a->id)['total'] === 0) {
            $problems[] = 'Agregue al menos una pregunta.';
        }
        if ($a->ends_at <= $a->starts_at) {
            $problems[] = 'La fecha de finalización debe ser posterior al inicio.';
        }

        return $problems;
    }

    public static function setStatus(Assessment $a, string $status, string $adminId, ?string $ip = null): void
    {
        $previous = $a->status;
        Assessment::whereKey($a->id)->firstOrFail()->update(['status' => $status]);
        Audit::log(
            $adminId,
            $status === 'archivado' ? 'assessment.archivado' : 'assessment.editado',
            'assessment',
            $a->id,
            ['estado' => $status, 'anterior' => $previous],
            $ip,
        );
    }

    /**
     * How much would be deleted with the assessment (for the confirmation and the audit).
     *
     * @return array{questions: int, participants: int, submitted: int}
     */
    public static function deletionSummary(string $id): array
    {
        $row = DB::selectOne(
            'select
                (select count(*) from questions where assessment_id = ?) as questions,
                (select count(*) from participants where assessment_id = ?) as participants,
                (select count(*) from submissions where assessment_id = ? and submitted_at is not null) as submitted',
            [$id, $id, $id],
        );

        return [
            'questions' => (int) $row->questions,
            'participants' => (int) $row->participants,
            'submitted' => (int) $row->submitted,
        ];
    }

    /**
     * Deletes an assessment for good. Foreign keys delete in cascade its questions, options,
     * participants, sessions, answers, AI analyses and email log. The audit log is kept, with
     * the title and the counts of what was deleted.
     */
    public static function delete(Assessment $a, string $adminId, ?string $ip = null): void
    {
        DB::transaction(function () use ($a, $adminId, $ip) {
            $summary = self::deletionSummary($a->id);
            Assessment::whereKey($a->id)->delete();
            Audit::log($adminId, 'assessment.eliminado', 'assessment', $a->id, [
                'titulo' => $a->title,
                'estado' => $a->status,
                'preguntas' => $summary['questions'],
                'participantes' => $summary['participants'],
                'entregas' => $summary['submitted'],
            ], $ip);
        });
    }

    /**
     * Duplicates an assessment with its questions and options (participants, answers and the
     * access password are not copied). The copy is a draft.
     */
    public static function duplicate(string $id, string $adminId, ?string $ip = null): ?Assessment
    {
        return DB::transaction(function () use ($id, $adminId, $ip) {
            $original = Assessment::find($id);
            if (! $original) {
                return null;
            }

            $copy = Assessment::create([
                'slug' => self::generateSlug(),
                'title' => mb_substr("Copia de {$original->title}", 0, 200),
                'description' => $original->description,
                'instructions' => $original->instructions,
                'starts_at' => $original->starts_at,
                'ends_at' => $original->ends_at,
                'timezone' => $original->timezone,
                'access_mode' => $original->access_mode,
                'open_max_chars' => $original->open_max_chars,
                'invite_subject' => $original->invite_subject,
                'invite_body' => $original->invite_body,
                // The copy belongs to the same client, so it keeps its brand (the password is not copied).
                'client_id' => $original->client_id,
                'status' => 'borrador',
                'duplicated_from_id' => $original->id,
                'created_by_id' => $adminId,
            ]);
            self::copyQuestions($original->id, $copy->id);

            Audit::log($adminId, 'assessment.duplicado', 'assessment', $copy->id, [
                'origen' => $original->id,
                'titulo' => $copy->title,
            ], $ip);

            return $copy->fresh();
        });
    }

    /** Copies the questions and options (with the expected one) to another assessment. Returns how many. */
    public static function copyQuestions(string $fromId, string $toId): int
    {
        $questions = Question::where('assessment_id', $fromId)->orderBy('position')->get();
        foreach ($questions as $q) {
            $newQ = Question::create([
                'assessment_id' => $toId,
                'type' => $q->type,
                'prompt' => $q->prompt,
                'position' => $q->position,
                'required' => $q->required,
            ]);
            foreach (QuestionOption::where('question_id', $q->id)->get() as $o) {
                QuestionOption::create([
                    'question_id' => $newQ->id,
                    'text' => $o->text,
                    'position' => $o->position,
                    'is_correct' => $o->is_correct,
                ]);
            }
        }

        return $questions->count();
    }
}
