<?php

namespace App\Services\Shared;

use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * Data every assessment tab shares (header, tabs, question lock) and the lookups behind it.
 * Pages render it through the Svelte `AssessmentLayout` with the `context` prop.
 */
final class AssessmentContext
{
    public const LOCKED_BY_RESPONSES =
        'Este assessment ya tiene respuestas: las preguntas no se pueden modificar. Duplíquelo para hacer cambios.';

    public const LOCKED_BY_SERIES =
        'Este assessment es parte de una serie de mediciones: todas comparten las mismas preguntas para poder compararlas, así que no se pueden modificar. Duplíquelo para hacer cambios.';

    /** Loads the assessment from the URL or responds 404 (ids must look like a UUID). */
    public static function require(string $id): Assessment
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            abort(404, 'No encontrado');
        }

        return Assessment::find($id) ?? abort(404, 'Assessment no encontrado');
    }

    /** Has any participant started or submitted? From then on the questions are locked. */
    public static function hasResponses(string $assessmentId): bool
    {
        return Submission::where('assessment_id', $assessmentId)->exists();
    }

    /** Series members in order. When it is not in a series: only itself. */
    public static function seriesMembers(Assessment $a): Collection
    {
        if (! $a->series_id) {
            return collect([$a]);
        }

        return Assessment::where('series_id', $a->series_id)
            ->orderBy('series_order')
            ->orderBy('created_at')
            ->get();
    }

    /** Are the questions shared with another measurement? (then they cannot be modified) */
    public static function inSharedSeries(string $assessmentId): bool
    {
        $seriesId = Assessment::whereKey($assessmentId)->value('series_id');

        return $seriesId && Assessment::where('series_id', $seriesId)->count() > 1;
    }

    /** Reason why the questions cannot be modified, or null when they can. */
    public static function questionsLockReason(string $assessmentId): ?string
    {
        if (self::inSharedSeries($assessmentId)) {
            return self::LOCKED_BY_SERIES;
        }
        if (self::hasResponses($assessmentId)) {
            return self::LOCKED_BY_RESPONSES;
        }

        return null;
    }

    /** @return array{choice: int, open: int, total: int} */
    public static function countQuestions(string $assessmentId): array
    {
        $rows = Question::where('assessment_id', $assessmentId)
            ->selectRaw('type, count(*) as n')
            ->groupBy('type')
            ->pluck('n', 'type');
        $choice = (int) ($rows['opcion_unica'] ?? 0);
        $open = (int) ($rows['abierta'] ?? 0);

        return ['choice' => $choice, 'open' => $open, 'total' => $choice + $open];
    }

    public static function publicLink(string $slug): string
    {
        return rtrim((string) config('app.url'), '/')."/a/{$slug}";
    }

    /**
     * Props for the shared header and tabs. Explicit fields: the access password hash never
     * leaves the server.
     */
    public static function props(Assessment $a): array
    {
        $brand = Brand::get($a->client_id);
        $lockReason = self::questionsLockReason($a->id);
        $members = self::seriesMembers($a);

        return [
            'assessment' => [
                'id' => $a->id,
                'title' => $a->title,
                'status' => $a->status,
                'slug' => $a->slug,
                'timezone' => $a->timezone,
                'accessMode' => $a->access_mode,
                'openMaxChars' => $a->open_max_chars,
                'startsAt' => $a->starts_at,
                'endsAt' => $a->ends_at,
                'hasPassword' => (bool) $a->access_password_hash,
                // Only when it can be decrypted: the password itself is requested separately (and audited).
                'passwordViewable' => (bool) $a->access_password_enc,
                'client' => $brand ? [
                    'id' => $brand['id'],
                    'name' => $brand['name'],
                    'themePrimary' => $brand['theme_primary'],
                    'themeAccent' => $brand['theme_accent'],
                ] : null,
                'logoUrl' => Brand::logoUrl($a->slug, $brand),
            ],
            'lockReason' => $lockReason,
            'locked' => $lockReason !== null,
            // Measurement series (only with more than one): for the Mediciones tab.
            'series' => $members->count() > 1
                ? $members->map(fn (Assessment $m) => [
                    'id' => $m->id, 'title' => $m->title, 'order' => $m->series_order, 'status' => $m->status,
                ])->values()->all()
                : null,
            'questionCounts' => self::countQuestions($a->id),
            'participantCount' => Participant::where('assessment_id', $a->id)->count(),
            'publicLink' => self::publicLink($a->slug),
        ];
    }
}
