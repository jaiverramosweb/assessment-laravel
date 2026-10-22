<?php

namespace App\Services\Ai\Analysis;

/** What the analysis service needs from the AI. Lets the tests use a fake client. */
interface Analyzer
{
    public function model(): string;

    /**
     * Analyzes one user prompt and returns a normalized result (passes AiStore::isValidResult).
     *
     * @throws AiError
     */
    public function analyze(string $userPrompt): array;
}
