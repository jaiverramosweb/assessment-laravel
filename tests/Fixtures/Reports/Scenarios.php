<?php

namespace Tests\Fixtures\Reports;

use App\Models\AdminUser;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\Client;
use App\Models\Participant;
use App\Models\Submission;
use App\Services\Assessments\Questions;
use App\Services\Assessments\Series;
use App\Services\Participants\Participants;
use App\Support\Clock;
use Illuminate\Support\Str;

/**
 * Report scenarios shared by the report, client report, comparison and export tests
 * (same data as the original vitest specs).
 */
final class Scenarios
{
    public static function admin(): AdminUser
    {
        return AdminUser::firstOrCreate(
            ['email' => 'a@b.co'],
            ['name' => 'Admin', 'password_hash' => 'x'],
        );
    }

    /** Assessment from the local wall time of America/Bogota ("2026-11-01T08:00"). */
    public static function assessment(array $attributes = []): Assessment
    {
        $tz = new \DateTimeZone('America/Bogota');
        $ms = fn (string $local) => (new \DateTimeImmutable($local, $tz))->getTimestamp() * 1000;

        return Assessment::create([
            'slug' => Str::lower(Str::random(12)),
            'title' => 'Reporte',
            'timezone' => 'America/Bogota',
            'access_mode' => 'invitados',
            'status' => 'borrador',
            ...$attributes,
            'starts_at' => $ms($attributes['starts_at'] ?? '2026-11-01T08:00'),
            'ends_at' => $ms($attributes['ends_at'] ?? '2026-11-15T18:00'),
        ]);
    }

    /** Replaces the questions. Options are [text, isCorrect]. */
    public static function questions(Assessment $a, array $list): array
    {
        Questions::replace($a->id, array_map(fn ($q) => $q['type'] === 'abierta'
            ? $q
            : [...$q, 'options' => array_map(fn ($o) => ['text' => $o[0], 'isCorrect' => $o[1] ?? false], $q['options'])],
            $list), null);

        return Questions::listForAdmin($a->id);
    }

    /** People as [name, email, area, cargo]. */
    public static function people(Assessment $a, array $people): void
    {
        foreach ($people as $p) {
            Participant::create([
                'assessment_id' => $a->id,
                'name' => $p[0],
                'email' => $p[1],
                'area' => ($p[2] ?? '') !== '' ? $p[2] : null,
                'cargo' => ($p[3] ?? '') !== '' ? $p[3] : null,
            ]);
        }
    }

    /**
     * Answers of a participant: question index => option text (choice) or text (open).
     */
    public static function respond(Assessment $a, string $name, array $answers, bool $submitted = true, ?int $submittedAt = null): void
    {
        $qs = Questions::listForAdmin($a->id);
        $pid = collect(Participants::listParticipants($a->id))->firstWhere('name', $name)['id'];
        $s = Submission::create([
            'participant_id' => $pid,
            'assessment_id' => $a->id,
            'submitted_at' => $submitted ? ($submittedAt ?? Clock::nowMs()) : null,
        ]);
        foreach ($answers as $i => $value) {
            $q = $qs[$i];
            if ($q['type'] === 'opcion_unica') {
                $option = collect($q['options'])->firstWhere('text', $value);
                Answer::create(['submission_id' => $s->id, 'question_id' => $q['id'], 'option_id' => $option['id']]);
            } else {
                Answer::create(['submission_id' => $s->id, 'question_id' => $q['id'], 'text' => $value]);
            }
        }
    }

    /**
     * reports.spec.ts: P1 (2 options, expected A), P2 (3 options, expected C), P3 open,
     * P4 optional (expected X). Ana (TI), Luis (TI), Eva (Finanzas) submitted; Teo (Finanzas)
     * is in progress; Sol never entered.
     */
    public static function report(): Assessment
    {
        $a = self::assessment(['title' => 'Reporte']);
        self::questions($a, [
            ['type' => 'opcion_unica', 'prompt' => 'P1', 'required' => true, 'options' => [['A', true], ['B']]],
            ['type' => 'opcion_unica', 'prompt' => 'P2', 'required' => true, 'options' => [['A'], ['B'], ['C', true]]],
            ['type' => 'abierta', 'prompt' => 'P3', 'required' => true],
            ['type' => 'opcion_unica', 'prompt' => 'P4', 'required' => false, 'options' => [['X', true], ['Y']]],
        ]);
        self::people($a, [
            ['Ana', 'ana@x.co', 'TI'],
            ['Luis', 'luis@x.co', 'TI'],
            ['Eva', 'eva@x.co', 'Finanzas'],
            ['Teo', 'teo@x.co', 'Finanzas'],
            ['Sol', 'sol@x.co', 'Finanzas'],
        ]);
        self::respond($a, 'Ana', [0 => 'A', 1 => 'C', 2 => 'Texto de Ana', 3 => 'X']);
        self::respond($a, 'Luis', [0 => 'A', 1 => 'B', 2 => 'Texto de Luis']);
        self::respond($a, 'Eva', [0 => 'B', 1 => 'C', 2 => 'Texto de Eva', 3 => 'Y']);
        self::respond($a, 'Teo', [0 => 'B', 1 => 'A', 2 => 'Borrador'], false);

        return $a;
    }

    /**
     * export.spec.ts: one choice and one open question, two participants (one name looks like a
     * formula), both submitted at 2026-11-05T15:30Z, and a stored AI analysis of the open one.
     */
    public static function export(): Assessment
    {
        $a = self::assessment(['title' => 'Seguridad de la Información', 'description' => 'Evaluación anual']);
        $qs = self::questions($a, [
            ['type' => 'opcion_unica', 'prompt' => '¿Qué es confidencialidad?', 'required' => true,
                'options' => [['Acceso de autorizados', true], ['Todo público']]],
            ['type' => 'abierta', 'prompt' => '¿Qué mejoraría?', 'required' => true],
        ]);
        self::people($a, [
            ['Ana', 'ana@x.co', 'TI'],
            ['=HYPERLINK("http://x")', 'luis@x.co', 'TI'],
        ]);
        $at = (new \DateTimeImmutable('2026-11-05T15:30:00Z'))->getTimestamp() * 1000;
        foreach (Participants::listParticipants($a->id) as $p) {
            self::respond($a, $p['name'], [0 => 'Acceso de autorizados', 1 => "Respuesta de {$p['email']}"], true, $at);
        }
        \App\Models\AiAnalysis::create([
            'assessment_id' => $a->id,
            'question_id' => $qs[1]['id'],
            'scope' => 'pregunta',
            'model' => 'claude-opus-5-5',
            'result' => self::analysis(),
            'input_hash' => 'x',
            'response_count' => 2,
        ]);

        return $a;
    }

    public static function analysis(): array
    {
        return [
            'summary' => 'Las personas valoran la capacitación.',
            'themes' => [['name' => 'Capacitación', 'description' => 'Piden más cursos.', 'frequency' => 'alta', 'approxCount' => 2]],
            'highlights' => ['Buena disposición'],
            'concerns' => ['Poco tiempo'],
            'quotes' => ['Necesitamos más práctica'],
        ];
    }

    /**
     * client-report.spec.ts: client with two published assessments (before and after) and a
     * draft that does not count. Ana (TI) and Eva (Finanzas) answer both; Luis (TI) only the
     * first. Another client's assessment must not leak in.
     *
     * @return array{client: Client, pre: Assessment, post: Assessment}
     */
    public static function client(): array
    {
        $client = Client::create(['name' => 'Acme']);
        $other = Client::create(['name' => 'Otro']);
        $make = function (string $title, string $startsAt, ?string $clientId = null, bool $publish = true) use ($client) {
            $a = self::assessment([
                'title' => $title,
                'client_id' => $clientId ?? $client->id,
                'starts_at' => $startsAt,
                'ends_at' => '2026-12-31T18:00',
                'status' => $publish ? 'publicado' : 'borrador',
            ]);
            self::questions($a, [
                ['type' => 'opcion_unica', 'prompt' => 'P1', 'required' => true, 'options' => [['Sí', true], ['No']]],
                ['type' => 'opcion_unica', 'prompt' => 'P2', 'required' => true, 'options' => [['Sí', true], ['No']]],
                ['type' => 'abierta', 'prompt' => 'Comentarios', 'required' => false],
            ]);

            return $a;
        };
        // The later one is created first to check they are sorted by start date.
        $post = $make('Después', '2026-12-01T08:00');
        $pre = $make('Antes', '2026-11-01T08:00');
        $make('Borrador', '2026-10-01T08:00', null, false);
        $foreign = $make('Ajeno', '2026-11-01T08:00', $other->id);

        foreach ([$pre, $post, $foreign] as $a) {
            self::people($a, [
                ['Ana', 'ana@x.co', 'TI', 'Analista'],
                ['Eva', 'eva@x.co', 'Finanzas', ''],
                ['Luis', 'luis@x.co', 'TI', 'Analista'],
            ]);
        }
        self::respond($pre, 'Ana', ['Sí', 'No']);   // 50 %
        self::respond($pre, 'Eva', ['No', 'No']);   // 0 %
        self::respond($pre, 'Luis', ['Sí', 'Sí']);  // 100 %
        self::respond($post, 'Ana', ['Sí', 'Sí']);  // 100 %
        self::respond($post, 'Eva', ['Sí', 'No']);  // 50 %
        self::respond($foreign, 'Ana', ['No', 'No']);

        return ['client' => $client, 'pre' => $pre, 'post' => $post];
    }

    /**
     * series.spec.ts (comparison): "Diagnóstico" with two choice questions (expected "Sí") and an
     * open one; before: Ana 1/2, Eva 0/2, Luis 2/2; after: Ana 2/2, Eva 1/2 (Luis did not answer).
     *
     * @return array{pre: Assessment, post: Assessment}
     */
    public static function series(): array
    {
        $pre = self::assessment([
            'title' => 'Diagnóstico',
            'instructions' => 'Responda con calma',
            'starts_at' => '2026-01-10T08:00',
            'ends_at' => '2026-01-20T18:00',
            'status' => 'publicado',
        ]);
        $opts = [['Sí', true], ['No'], ['No sé']];
        self::questions($pre, [
            ['type' => 'opcion_unica', 'prompt' => 'P1', 'required' => true, 'options' => $opts],
            ['type' => 'opcion_unica', 'prompt' => 'P2', 'required' => true, 'options' => $opts],
            ['type' => 'abierta', 'prompt' => '¿Qué aprendió?', 'required' => false],
        ]);
        self::people($pre, [['Ana', 'ana@x.co', 'TI'], ['Eva', 'eva@x.co', 'Finanzas'], ['Luis', 'luis@x.co', 'TI']]);
        $post = Series::createNextMeasurement($pre->id, self::admin()->id);
        $post->update(['status' => 'publicado']);

        self::respond($pre, 'Ana', ['Sí', 'No', 'Poco']);
        self::respond($pre, 'Eva', ['No', 'No sé']);
        self::respond($pre, 'Luis', ['Sí', 'Sí']);
        self::respond($post, 'Ana', ['Sí', 'Sí', 'Mucho']);
        self::respond($post, 'Eva', ['Sí', 'No']);

        return ['pre' => $pre->fresh(), 'post' => $post->fresh()];
    }
}
