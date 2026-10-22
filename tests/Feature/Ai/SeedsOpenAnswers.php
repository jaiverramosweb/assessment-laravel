<?php

namespace Tests\Feature\Ai;

use App\Models\AdminUser;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\Submission;
use App\Services\Assessments\Questions;
use App\Support\Clock;

/**
 * Same data as the original ai-analysis.spec.ts: two open questions and two final submissions
 * whose answers mention the participants by name and email.
 */
trait SeedsOpenAnswers
{
    protected AdminUser $admin;

    protected Assessment $assessment;

    /** @var string[] */
    protected array $openIds;

    protected function seedOpenAnswers(): void
    {
        $this->admin = $this->createAdmin();
        $this->assessment = $this->createAssessment(['title' => 'Clima']);
        Questions::replace($this->assessment->id, [
            ['type' => 'abierta', 'prompt' => '¿Qué mejoraría?', 'required' => true],
            ['type' => 'abierta', 'prompt' => '¿Qué valora?', 'required' => false],
        ], $this->admin->id);
        $this->openIds = array_column(Questions::listForAdmin($this->assessment->id), 'id');

        $this->addSubmission('Carolina Ruiz', 'carolina@empresa.com', [
            'Yo, Carolina Ruiz, pido más tiempo. Escriban a carolina@empresa.com',
            'El equipo',
        ]);
        $this->addSubmission('Mateo Díaz', 'mateo@empresa.com', [
            'Hablé con Mateo y con Carolina: falta tiempo',
            null,
        ]);
    }

    /** @param  array<int, ?string>  $texts  one per open question (null = no answer) */
    protected function addSubmission(string $name, string $email, array $texts, bool $submitted = true): void
    {
        $p = $this->createParticipant($this->assessment, ['name' => $name, 'email' => $email]);
        $s = Submission::create([
            'participant_id' => $p->id,
            'assessment_id' => $this->assessment->id,
            'submitted_at' => $submitted ? Clock::nowMs() : null,
        ]);
        foreach ($texts as $i => $text) {
            if ($text !== null) {
                Answer::create(['submission_id' => $s->id, 'question_id' => $this->openIds[$i], 'text' => $text]);
            }
        }
    }

    /** A valid model output (the structured JSON the API returns). */
    protected static function aiResult(): array
    {
        return [
            'summary' => 'Resumen de prueba.',
            'themes' => [['name' => 'Tiempo', 'description' => 'Falta de tiempo.', 'frequency' => 'alta', 'approxCount' => 2]],
            'highlights' => ['Compromiso'],
            'concerns' => ['Carga laboral'],
            'quotes' => ['No tengo tiempo'],
        ];
    }
}
