<?php

namespace App\Jobs;

use App\Services\Ai\AiSettings;
use App\Services\Ai\Analysis\AiAnalysisService;
use App\Services\Ai\Analysis\AiError;
use App\Services\Ai\Analysis\AiJobState;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * AI analysis of one open question (or the global one when `questionId` is null), run by the
 * queue worker that cron starts (`schedule:run`), so it does not depend on web time limits.
 * The web request marks the key pending; this job always clears it and, when the analysis fails,
 * leaves the Spanish message for the reports page.
 */
class AnalyzeOpenAnswers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Run once: a failed call is reported to the admin, who can try again. */
    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(
        public string $assessmentId,
        public ?string $questionId,
        public ?string $adminId,
        public bool $force,
        public ?string $ip,
    ) {
        $this->timeout = AiJobState::jobTimeout();
        $this->uniqueFor = AiJobState::pendingTtl();
    }

    /** The same question (or the global analysis) never runs twice at the same time. */
    public function uniqueId(): string
    {
        return $this->assessmentId.':'.$this->key();
    }

    public function key(): string
    {
        return $this->questionId ?? 'global';
    }

    public function handle(): void
    {
        try {
            $analyzer = AiSettings::analyzer() ?? throw new AiError(AiSettings::NOT_CONFIGURED);
            if ($this->questionId === null) {
                AiAnalysisService::analyzeGlobal($analyzer, $this->assessmentId, $this->adminId, $this->force, $this->ip);
            } else {
                AiAnalysisService::analyzeQuestion($analyzer, $this->assessmentId, $this->questionId, $this->adminId, $this->force, $this->ip);
            }
            AiJobState::clearError($this->assessmentId, $this->key());
        } catch (AiError $e) {
            AiJobState::setError($this->assessmentId, $this->key(), $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            AiJobState::setError($this->assessmentId, $this->key(), 'Ocurrió un error inesperado al analizar con IA.');
        } finally {
            AiJobState::clearPending($this->assessmentId, $this->key());
        }
    }

    /** Called by the worker when the job dies (e.g. it exceeded its timeout). */
    public function failed(?Throwable $e): void
    {
        AiJobState::setError($this->assessmentId, $this->key(), 'Ocurrió un error inesperado al analizar con IA.');
        AiJobState::clearPending($this->assessmentId, $this->key());
    }
}
