<?php

namespace Tests\Feature\Assessments;

use App\Services\Assessments\AssessmentForm;
use App\Services\Assessments\LocalDateTime;
use App\Services\Assessments\QuestionValidator;
use Tests\Fixtures\Assessments\Builders;
use Tests\TestCase;

/** Port of the assessment/question zod schemas (schemas.spec.ts + assessments.spec.ts dates). */
class ValidationTest extends TestCase
{
    public function test_dates_are_converted_to_utc_with_the_timezone(): void
    {
        $data = Builders::input();
        // 2026-11-01T13:00:00.000Z
        $this->assertSame(1793538000000, $data['starts_at']);
        $this->assertSame('Evaluación de seguridad', $data['title']);
        $this->assertNull($data['client_id']);
        $this->assertSame(8000, $data['open_max_chars']);

        $bad = AssessmentForm::validate(Builders::formValues(['startsAt' => '2026-11-15T18:00', 'endsAt' => '2026-11-01T08:00']));
        $this->assertSame(['endsAt' => 'La finalización debe ser posterior al inicio.'], $bad['errors']);
    }

    public function test_field_messages_match_the_original(): void
    {
        $r = AssessmentForm::validate(Builders::formValues([
            'title' => ' ab ', 'timezone' => 'Marte/Base', 'startsAt' => '', 'endsAt' => '',
            'accessMode' => 'todos', 'openMaxChars' => '12.5', 'description' => str_repeat('x', 5001),
        ]));
        $this->assertSame([
            'title' => 'El título debe tener al menos 3 caracteres.',
            'description' => 'Descripción: máximo 5.000 caracteres.',
            'timezone' => 'Zona horaria no válida.',
            'startsAt' => 'Indique la fecha y hora de inicio.',
            'endsAt' => 'Indique la fecha y hora de finalización.',
            'accessMode' => 'Modo de acceso no válido.',
            'openMaxChars' => 'El límite de caracteres debe ser un número entero.',
        ], $r['errors']);

        $this->assertSame(
            ['openMaxChars' => 'El límite de caracteres debe ser un número.'],
            AssessmentForm::validate(Builders::formValues(['openMaxChars' => 'mucho']))['errors'],
        );
        $this->assertSame(
            ['openMaxChars' => 'El límite de caracteres debe ser al menos 500.'],
            AssessmentForm::validate(Builders::formValues(['openMaxChars' => '']))['errors'],
        );
        $this->assertSame(
            ['openMaxChars' => 'El límite de caracteres no puede superar 50.000.'],
            AssessmentForm::validate(Builders::formValues(['openMaxChars' => '50001']))['errors'],
        );
        $this->assertSame(
            ['title' => 'Título: máximo 200 caracteres.'],
            AssessmentForm::validate(Builders::formValues(['title' => str_repeat('t', 201)]))['errors'],
        );
        $this->assertSame(
            ['startsAt' => 'Fecha de inicio no válida.'],
            AssessmentForm::validate(Builders::formValues(['startsAt' => '2026-02-31T08:00']))['errors'],
        );
    }

    public function test_local_date_time_round_trip_and_validation(): void
    {
        $ms = LocalDateTime::parse('2026-11-01T08:00', 'America/Bogota');
        $this->assertSame('2026-11-01T08:00', LocalDateTime::format($ms, 'America/Bogota'));
        $this->assertSame('2026-11-01T14:00', LocalDateTime::format($ms, 'Europe/Madrid'));
        $this->assertNull(LocalDateTime::parse('2026-13-01T08:00', 'UTC'));
        $this->assertNull(LocalDateTime::parse('01/11/2026 08:00', 'UTC'));
        $this->assertTrue(LocalDateTime::isValidTimeZone('America/Argentina/Buenos_Aires'));
        $this->assertFalse(LocalDateTime::isValidTimeZone('Marte/Base'));
        $all = LocalDateTime::allTimeZones();
        $this->assertSame('America/Bogota', $all[0]);
        $this->assertSame(count($all), count(array_unique($all)));
    }

    public function test_access_password_requires_12_characters_and_matching_confirmation(): void
    {
        $this->assertSame(
            ['password' => 'La contraseña de acceso debe tener al menos 12 caracteres.'],
            AssessmentForm::validateAccessPassword('acceso-2026', 'acceso-2026'),
        );
        $this->assertSame([], AssessmentForm::validateAccessPassword('acceso-20261', 'acceso-20261'));
        $this->assertSame(
            ['confirm' => 'Las contraseñas no coinciden.'],
            AssessmentForm::validateAccessPassword('acceso-2026-ok', 'otra-cosa'),
        );
        $this->assertSame(
            ['password' => 'La contraseña de acceso es demasiado larga.'],
            AssessmentForm::validateAccessPassword(str_repeat('a', 101), str_repeat('a', 101)),
        );
    }

    public function test_question_list_messages_name_question_and_option(): void
    {
        $r = QuestionValidator::validateList([
            ['type' => 'opcion_unica', 'prompt' => '  ', 'required' => true, 'options' => [
                ['text' => 'A', 'isCorrect' => true], ['text' => ' ', 'isCorrect' => false],
            ]],
            ['type' => 'opcion_unica', 'prompt' => 'Ok', 'required' => true, 'options' => [
                ['text' => 'A', 'isCorrect' => false], ['text' => 'a', 'isCorrect' => false],
            ]],
            ['type' => 'opcion_unica', 'prompt' => 'Una', 'required' => true, 'options' => [['text' => 'A', 'isCorrect' => true]]],
            ['type' => 'encuesta', 'prompt' => 'x', 'required' => true],
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame([
            'Pregunta 1: Escriba el enunciado.',
            'Pregunta 1, opción 2: Hay una opción vacía.',
            'Pregunta 2: Marque exactamente una opción correcta.',
            'Pregunta 2: Hay opciones repetidas.',
            'Pregunta 3: Debe tener al menos 2 opciones.',
            'Pregunta 4: Tipo de pregunta no válido.',
        ], $r['errors']);
    }

    public function test_question_list_output_is_trimmed_and_open_questions_drop_options(): void
    {
        $r = QuestionValidator::validateList([
            ['type' => 'abierta', 'prompt' => ' Explique ', 'required' => false, 'options' => [['text' => 'x', 'isCorrect' => true]]],
            ['type' => 'opcion_unica', 'prompt' => '¿2+2?', 'required' => true, 'options' => [
                ['text' => ' 3 ', 'isCorrect' => false], ['text' => '4', 'isCorrect' => true],
            ]],
        ]);
        $this->assertSame([
            ['type' => 'abierta', 'prompt' => 'Explique', 'required' => false],
            ['type' => 'opcion_unica', 'prompt' => '¿2+2?', 'required' => true, 'options' => [
                ['text' => '3', 'isCorrect' => false], ['text' => '4', 'isCorrect' => true],
            ]],
        ], $r['questions']);
    }

    public function test_question_list_limits(): void
    {
        $many = array_fill(0, 201, ['type' => 'abierta', 'prompt' => 'x', 'required' => true]);
        $this->assertSame(['Máximo 200 preguntas por assessment.'], QuestionValidator::validateList($many)['errors']);
        $seven = array_map(fn ($i) => ['text' => "O{$i}", 'isCorrect' => $i === 0], range(0, 6));
        $r = QuestionValidator::validateList([['type' => 'opcion_unica', 'prompt' => 'P', 'required' => true, 'options' => $seven]]);
        $this->assertSame(['Pregunta 1: Puede tener máximo 6 opciones.'], $r['errors']);
        $r = QuestionValidator::validateList([['type' => 'abierta', 'prompt' => str_repeat('x', 2001), 'required' => true]]);
        $this->assertSame(['Pregunta 1: El enunciado no puede superar 2.000 caracteres.'], $r['errors']);
    }
}
