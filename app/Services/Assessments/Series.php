<?php

namespace App\Services\Assessments;

use App\Models\Assessment;
use App\Security\Audit;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Measurement series (port of src/lib/server/services/series.ts): the same assessment applied
 * several times (before and after a training, follow-up…) to compare them. Each measurement is a
 * normal assessment with its own link, password, dates and participants; what they share are the
 * questions. For an exact comparison the questions of a series cannot be modified and are paired
 * by position. Membership lookups live in App\Services\Shared\AssessmentContext.
 */
final class Series
{
    private const SUFFIX = '/ · Medición \d+$/u';

    private const DAY = 24 * 3600 * 1000;

    /**
     * Creates the next measurement of the series from `$sourceId`: copies the questions (with the
     * expected option), client, texts, invitation template and participants (not marked as
     * invited). It is a draft, without password, with proposed dates.
     */
    public static function createNextMeasurement(string $sourceId, string $adminId, ?string $ip = null): ?Assessment
    {
        return DB::transaction(function () use ($sourceId, $adminId, $ip) {
            $source = Assessment::find($sourceId);
            if (! $source) {
                return null;
            }

            $seriesId = $source->series_id ?? $source->id;
            if (! $source->series_id) {
                $source->update(['series_id' => $seriesId, 'series_order' => 1]);
            }
            $order = ((int) (Assessment::where('series_id', $seriesId)->max('series_order') ?? 1)) + 1;

            // Proposed dates: starts one day after the source closes (or after today) and lasts
            // the same. They are adjusted in the General tab.
            $duration = max($source->ends_at - $source->starts_at, self::DAY);
            $startsAt = max($source->ends_at, Clock::nowMs()) + self::DAY;
            $endsAt = $startsAt + $duration;

            $copy = Assessment::create([
                'slug' => Assessments::generateSlug(),
                'title' => mb_substr(preg_replace(self::SUFFIX, '', $source->title)." · Medición {$order}", 0, 200),
                'client_id' => $source->client_id,
                'description' => $source->description,
                'instructions' => $source->instructions,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $source->timezone,
                'access_mode' => $source->access_mode,
                'open_max_chars' => $source->open_max_chars,
                'invite_subject' => $source->invite_subject,
                'invite_body' => $source->invite_body,
                'status' => 'borrador',
                'series_id' => $seriesId,
                'series_order' => $order,
                'created_by_id' => $adminId,
            ]);

            $questionCount = Assessments::copyQuestions($source->id, $copy->id);

            // Raw copy of the participants (extra stays byte-for-byte), not marked as invited.
            $now = Clock::nowMs();
            $people = DB::table('participants')->where('assessment_id', $source->id)->get();
            foreach ($people as $p) {
                DB::table('participants')->insert([
                    'id' => (string) Str::uuid(),
                    'assessment_id' => $copy->id,
                    'name' => $p->name,
                    'email' => $p->email,
                    'area' => $p->area,
                    'cargo' => $p->cargo,
                    'extra' => $p->extra,
                    'source' => $p->source,
                    'created_at' => $now,
                ]);
            }

            Audit::log($adminId, 'assessment.medicion_creada', 'assessment', $copy->id, [
                'titulo' => $copy->title,
                'medicion' => $order,
                'origen' => $source->id,
                'preguntas' => $questionCount,
                'participantes' => $people->count(),
            ], $ip);

            return $copy->fresh();
        });
    }
}
