<?php

namespace Tests\Feature\ParticipantFlow;

use App\Auth\ParticipantSessions;
use App\Auth\Passwords;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\ParticipantFlow\Access;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same fixture as the original access.spec.ts: an assessment open from 2026-11-01 08:00 to
 * 2026-11-15 18:00 (Bogotá), three questions, a shared password and one invited participant.
 */
abstract class ParticipantFlowTestCase extends TestCase
{
    use RefreshDatabase;

    protected const HOUR = 3600 * 1000;

    protected const PASSWORD = 'acceso-2026';

    /** 2026-11-05T15:00:00Z */
    protected const NOW = 1793890800000;

    /** 2026-11-01T08:00 America/Bogota = 13:00Z */
    protected const STARTS = 1793538000000;

    /** 2026-11-15T18:00 America/Bogota = 23:00Z */
    protected const ENDS = 1794783600000;

    protected Assessment $assessment;

    protected Participant $ana;

    protected string $hash;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze(self::NOW);
        $this->hash = Passwords::hash(self::PASSWORD);
        $this->setUpAssessment();
    }

    protected function setUpAssessment(string $accessMode = 'invitados'): void
    {
        $this->assessment = $this->createAssessment([
            'title' => 'Seguridad',
            'starts_at' => self::STARTS,
            'ends_at' => self::ENDS,
            'timezone' => 'America/Bogota',
            'access_mode' => $accessMode,
            'open_max_chars' => 500,
            'status' => 'publicado',
            'access_password_hash' => $this->hash,
        ]);

        $mc = Question::create([
            'assessment_id' => $this->assessment->id, 'type' => 'opcion_unica',
            'prompt' => '¿Qué es confidencialidad?', 'position' => 0, 'required' => true,
        ]);
        QuestionOption::create(['question_id' => $mc->id, 'text' => 'Acceso solo de autorizados', 'position' => 0, 'is_correct' => true]);
        QuestionOption::create(['question_id' => $mc->id, 'text' => 'Información pública', 'position' => 1, 'is_correct' => false]);
        Question::create([
            'assessment_id' => $this->assessment->id, 'type' => 'abierta',
            'prompt' => 'Comente', 'position' => 1, 'required' => true,
        ]);
        Question::create([
            'assessment_id' => $this->assessment->id, 'type' => 'abierta',
            'prompt' => 'Opcional', 'position' => 2, 'required' => false,
        ]);

        $this->ana = $this->createParticipant($this->assessment, ['name' => 'Ana Pérez', 'email' => 'ana@x.co']);
    }

    protected function fresh(): Assessment
    {
        return Assessment::findOrFail($this->assessment->id);
    }

    protected function enter(string $email, string $password = self::PASSWORD, array $extra = []): array
    {
        return Access::enter(
            $this->fresh(),
            $email,
            $password,
            $extra['name'] ?? null,
            $extra['ip'] ?? '10.0.0.1',
            $extra['at'] ?? self::NOW,
        );
    }

    /** Enters and returns the participant context (plus its token), as a real request would. */
    protected function login(string $email = 'ana@x.co'): array
    {
        $r = $this->enter($email);
        $this->assertTrue($r['ok'], 'no ingresó: '.($r['reason'] ?? ''));
        $session = ParticipantSessions::create($r['participantId'], 72, self::NOW);
        $ctx = Access::context($session['token'], $this->assessment->slug, 72, self::NOW);
        $this->assertSame('ok', $ctx['kind']);

        return [...$ctx, 'token' => $session['token']];
    }
}
