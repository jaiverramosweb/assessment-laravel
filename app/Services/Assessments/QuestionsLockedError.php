<?php

namespace App\Services\Assessments;

use App\Services\Shared\AssessmentContext;
use RuntimeException;

/** The questions cannot be modified (there are responses, or the assessment is in a series). */
class QuestionsLockedError extends RuntimeException
{
    public function __construct(string $message = AssessmentContext::LOCKED_BY_RESPONSES)
    {
        parent::__construct($message);
    }
}
