<?php

namespace Tests\Feature\ParticipantFlow;

use App\Auth\ParticipantSessions;
use App\Auth\Passwords;
use App\Models\Participant;
use App\Models\Submission;
use App\Services\ParticipantFlow\AlreadySubmittedException;
use App\Services\ParticipantFlow\Access;
use App\Services\ParticipantFlow\Answers;

/** Port of the original src/lib/server/services/access.spec.ts. */
class AccessServiceTest extends ParticipantFlowTestCase
{
    // ---- disponibilidad -----------------------------------------------------

    public function test_availability_depends_on_status_and_date_range(): void
    {
        $now = self::NOW;
        $base = ['status' => 'publicado', 'starts_at' => $now - self::HOUR, 'ends_at' => $now + self::HOUR];

        $this->assertSame('abierto', Access::availability($base, $now));
        $this->assertSame('no_disponible', Access::availability([...$base, 'status' => 'borrador'], $now));
        $this->assertSame('no_disponible', Access::availability([...$base, 'status' => 'archivado'], $now));
        $this->assertSame('no_iniciado', Access::availability([...$base, 'starts_at' => $now + 1], $now));
        $this->assertSame('cerrado', Access::availability([...$base, 'ends_at' => $now], $now));
    }

    public function test_find_by_slug_rejects_malformed_slugs(): void
    {
        $this->assertNotNull(Access::findBySlug($this->assessment->slug));
        $this->assertNull(Access::findBySlug('ABC'));
        $this->assertNull(Access::findBySlug('../x'));
        $this->assertNull(Access::findBySlug('noexiste123'));
    }

    // ---- ingreso del participante ---------------------------------------------

    public function test_rejects_wrong_password_and_not_invited_emails(): void
    {
        $this->assertSame(['ok' => false, 'reason' => 'invalid_password'], $this->enter('ana@x.co', 'mala'));
        $this->assertSame(['ok' => false, 'reason' => 'not_invited'], $this->enter('intruso@x.co'));
    }

    public function test_creates_the_in_progress_submission_on_first_entry_and_reuses_it(): void
    {
        $r1 = $this->enter('ana@x.co');
        $r2 = $this->enter('ana@x.co');

        $this->assertSame($r1, $r2);
        $this->assertSame(1, Submission::count());
        $this->assertNull(Submission::first()->submitted_at);
        $this->assertSame(self::NOW, Submission::first()->started_at);
    }

    public function test_only_works_inside_the_dates(): void
    {
        $this->assertSame(['ok' => false, 'reason' => 'no_iniciado'], $this->enter('ana@x.co', self::PASSWORD, ['at' => 1793361600000]));
        $this->assertSame(['ok' => false, 'reason' => 'cerrado'], $this->enter('ana@x.co', self::PASSWORD, ['at' => 1794830400000]));
    }

    public function test_a_draft_or_archived_assessment_does_not_admit_entries(): void
    {
        $this->assessment->update(['status' => 'archivado']);
        $this->assertSame(['ok' => false, 'reason' => 'no_disponible'], $this->enter('ana@x.co'));
    }

    public function test_locks_the_email_after_five_failures_even_with_the_right_password(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->enter('ana@x.co', 'mala');
        }

        $this->assertSame('locked', $this->enter('ana@x.co', 'mala')['reason']);
        $this->assertSame('locked', $this->enter('ana@x.co')['reason']);
        $this->assertSame(self::NOW + 15 * 60 * 1000, $this->enter('ana@x.co')['until']);
    }

    public function test_failures_from_another_ip_do_not_lock_the_persons_email(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->enter('ana@x.co', 'mala', ['ip' => '203.0.113.9']);
        }

        $this->assertSame('locked', $this->enter('ana@x.co', 'mala', ['ip' => '203.0.113.9'])['reason']);
        $this->assertTrue($this->enter('ana@x.co')['ok']);
    }

    public function test_after_a_password_change_active_sessions_continue_and_new_entries_use_the_new_one(): void
    {
        $ctx = $this->login();
        $this->assessment->update(['access_password_hash' => Passwords::hash('nueva-clave-1')]);

        $this->assertSame('ok', Access::context($ctx['token'], $this->assessment->slug, 72, self::NOW)['kind']);
        $this->assertSame(['ok' => false, 'reason' => 'invalid_password'], $this->enter('ana@x.co'));
        $this->assertTrue($this->enter('ana@x.co', 'nueva-clave-1')['ok']);
    }

    public function test_open_mode_asks_for_the_name_and_registers_the_person(): void
    {
        $this->setUpAssessment('abierto');

        $this->assertSame(['ok' => false, 'reason' => 'name_required'], $this->enter('nuevo@x.co'));
        $r = $this->enter('nuevo@x.co', self::PASSWORD, ['name' => 'Persona Nueva']);
        $this->assertTrue($r['ok']);

        $p = Participant::where('assessment_id', $this->assessment->id)->where('email', 'nuevo@x.co')->first();
        $this->assertSame('Persona Nueva', $p->name);
        $this->assertSame('abierto', $p->source);
        $this->assertSame($p->id, $r['participantId']);
    }

    public function test_assessment_without_password_rejects_every_entry(): void
    {
        $this->assessment->update(['access_password_hash' => null]);
        $this->assertSame(['ok' => false, 'reason' => 'invalid_password'], $this->enter('ana@x.co', ''));
    }

    // ---- contexto en cada petición --------------------------------------------

    public function test_context_rejects_another_slug_invented_token_and_expired_dates(): void
    {
        $ctx = $this->login();

        $this->assertSame('sin_sesion', Access::context($ctx['token'], 'otroslug123', 72, self::NOW)['kind']);
        $this->assertSame('sin_sesion', Access::context('inventado', $this->assessment->slug, 72, self::NOW)['kind']);
        $this->assertSame('sin_sesion', Access::context(null, $this->assessment->slug, 72, self::NOW)['kind']);

        $this->assessment->update(['ends_at' => self::NOW - 1]);
        $this->assertSame('cerrado', Access::context($ctx['token'], $this->assessment->slug, 72, self::NOW)['kind']);
    }

    // ---- respuestas ----------------------------------------------------------

    public function test_participant_questions_never_include_the_correct_option(): void
    {
        $qs = Answers::questionsFor($this->assessment->id);

        $this->assertCount(3, $qs);
        $this->assertDoesNotMatchRegularExpression('/isCorrect|is_correct|correct/i', json_encode($qs));
        $keys = array_keys($qs[0]['options'][0]);
        sort($keys);
        $this->assertSame(['id', 'text'], $keys);
        $this->assertSame(['id', 'type', 'prompt', 'required', 'options'], array_keys($qs[0]));
        $this->assertTrue($qs[0]['required']);
        $this->assertFalse($qs[2]['required']);
    }

    public function test_saves_updates_and_deletes_answers_validating_option_and_length(): void
    {
        $ctx = $this->login();
        [$mc, $open] = Answers::questionsFor($this->assessment->id);

        $this->assertSame(['ok' => false, 'errors' => ['Pregunta 1: opción no válida.']],
            Answers::save($ctx, [['questionId' => $mc['id'], 'value' => 'opcion-falsa']]));
        $this->assertSame(['ok' => false, 'errors' => ['Pregunta 2: supera el máximo de 500 caracteres.']],
            Answers::save($ctx, [['questionId' => $open['id'], 'value' => str_repeat('x', 501)]]));
        $this->assertSame(['ok' => false, 'errors' => ['Se recibió una respuesta para una pregunta que no existe.']],
            Answers::save($ctx, [['questionId' => 'no-existe', 'value' => 'x']]));

        Answers::save($ctx, [
            ['questionId' => $mc['id'], 'value' => $mc['options'][1]['id']],
            ['questionId' => $open['id'], 'value' => 'Primera versión'],
        ]);
        Answers::save($ctx, [['questionId' => $mc['id'], 'value' => $mc['options'][0]['id']]]);
        $saved = Answers::savedFor($ctx['submission']->id);
        $this->assertSame($mc['options'][0]['id'], $saved[$mc['id']]['optionId']);
        $this->assertSame('Primera versión', $saved[$open['id']]['text']);

        Answers::save($ctx, [['questionId' => $open['id'], 'value' => '   ']]);
        $this->assertArrayNotHasKey($open['id'], Answers::savedFor($ctx['submission']->id));
    }

    public function test_open_text_keeps_its_spaces_and_normalizes_line_breaks(): void
    {
        $ctx = $this->login();
        [, $open] = Answers::questionsFor($this->assessment->id);

        Answers::save($ctx, [['questionId' => $open['id'], 'value' => "  uno\r\ndos  "]]);
        $this->assertSame("  uno\ndos  ", Answers::savedFor($ctx['submission']->id)[$open['id']]['text']);
    }

    public function test_requires_the_mandatory_ones_submits_once_and_closes_the_session(): void
    {
        $ctx = $this->login();
        [$mc, $open] = Answers::questionsFor($this->assessment->id);

        Answers::save($ctx, [['questionId' => $mc['id'], 'value' => $mc['options'][0]['id']]]);
        $this->assertSame(['ok' => false, 'missing' => [2]], Answers::submit($ctx, self::NOW));

        Answers::save($ctx, [['questionId' => $open['id'], 'value' => 'Mi respuesta']]);
        $this->assertSame(['ok' => true], Answers::submit($ctx, self::NOW));

        // Can no longer save, submit again or enter again.
        try {
            Answers::save($ctx, [['questionId' => $open['id'], 'value' => 'cambio']]);
            $this->fail('save after submit must throw');
        } catch (AlreadySubmittedException) {
        }
        try {
            Answers::submit($ctx, self::NOW);
            $this->fail('second submit must throw');
        } catch (AlreadySubmittedException) {
        }
        $this->assertSame('sin_sesion', Access::context($ctx['token'], $this->assessment->slug, 72, self::NOW)['kind']);
        $entry = $this->enter('ana@x.co');
        $this->assertSame('already_submitted', $entry['reason']);
        $this->assertSame(self::NOW, $entry['submittedAt']);
        $this->assertSame('Mi respuesta', Answers::savedFor($ctx['submission']->id)[$open['id']]['text']);
        $this->assertSame(0, \App\Models\ParticipantSession::count());
    }

    public function test_long_date_matches_the_original_format(): void
    {
        $this->assertSame("1 de noviembre de 2026 a las 8:00\u{00A0}a.\u{00A0}m.",
            \App\Services\ParticipantFlow\Dates::long(self::STARTS, 'America/Bogota'));
        $this->assertSame("15 de noviembre de 2026 a las 6:00\u{00A0}p.\u{00A0}m.",
            \App\Services\ParticipantFlow\Dates::long(self::ENDS, 'America/Bogota'));
    }
}
