<?php

namespace App\Services\Ai\Analysis;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real Anthropic client over the Messages API (port of src/lib/server/ai/client.ts, which used
 * `client.beta.messages.parse` of the Node SDK). Only used on the server: the key never reaches
 * the browser and is never logged.
 * - Structured output (`output_config.format`) validated and normalized before storing.
 * - Effort "medium": a good balance between quality and cost for summarizing text.
 * - Server-side fallback when the model declines the request (`fallbacks: "default"`).
 * - Same retries as the SDK (`maxRetries: 2`): 408, 409, 429, 5xx and connection errors.
 */
final class AnthropicAnalyzer implements Analyzer
{
    public const URL = 'https://api.anthropic.com/v1/messages?beta=true';

    // Models that accept the automatic server fallback on a refusal.
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    private const MAX_RETRIES = 2;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutMs,
    ) {}

    /** Client configured from config/assessments.php, or null when ANTHROPIC_API_KEY is missing. */
    public static function fromConfig(): ?self
    {
        $key = (string) config('assessments.anthropic.api_key');

        return $key === '' ? null : new self(
            $key,
            (string) config('assessments.anthropic.model'),
            (int) config('assessments.anthropic.timeout_ms'),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    public function analyze(string $userPrompt): array
    {
        $withFallback = in_array($this->model, self::FALLBACK_MODELS, true);
        $body = [
            'model' => $this->model,
            'max_tokens' => 16000,
            'system' => Prompts::SYSTEM,
            'messages' => [['role' => 'user', 'content' => $userPrompt]],
            'output_config' => [
                'effort' => 'medium',
                'format' => ['type' => 'json_schema', 'schema' => AnalysisNormalizer::outputSchema()],
            ],
            ...($withFallback ? ['fallbacks' => 'default'] : []),
        ];
        $betas = [...($withFallback ? ['server-side-fallback-2026-07-01'] : []), 'structured-outputs-2025-12-15'];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => implode(',', $betas),
            ])
                ->acceptJson()
                ->timeout(max(1, $this->timeoutMs) / 1000)
                ->retry(
                    self::MAX_RETRIES + 1,
                    fn (int $attempt) => min(8000, 500 * 2 ** ($attempt - 1)),
                    fn (Throwable $e) => $e instanceof ConnectionException || self::retryable($e->response?->status() ?? 0),
                    throw: false,
                )
                ->post(self::URL, $body);
        } catch (ConnectionException $e) {
            throw self::isTimeout($e)
                ? new AiError('La IA tardó demasiado en responder. Intente de nuevo; si persiste, aumente AI_TIMEOUT_MS.')
                : new AiError('No se pudo conectar con el servicio de IA. Revise la conexión a internet.');
        } catch (Throwable $e) {
            Log::error('[ia] error inesperado', ['error' => $e->getMessage()]);

            throw new AiError('Ocurrió un error inesperado al analizar con IA.');
        }

        if (! $response->successful()) {
            throw self::statusError($response);
        }

        $message = $response->json();
        if (($message['stop_reason'] ?? null) === 'refusal') {
            throw new AiError('La IA no pudo analizar este contenido. Revise las respuestas o intente más tarde.');
        }
        $result = ($message['stop_reason'] ?? null) === 'max_tokens' ? null : self::parsedOutput($message);
        if ($result === null) {
            throw new AiError('La IA devolvió una respuesta incompleta. Intente de nuevo.');
        }

        return $result;
    }

    /** First text block parsed and normalized (what the SDK exposed as `parsed_output`). */
    private static function parsedOutput(mixed $message): ?array
    {
        foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return AnalysisNormalizer::normalize(json_decode($block['text'], true));
            }
        }

        return null;
    }

    private static function retryable(int $status): bool
    {
        return in_array($status, [408, 409, 429], true) || $status >= 500;
    }

    /** Translates API statuses (like the SDK's typed errors) to clear Spanish messages. */
    private static function statusError(Response $response): AiError
    {
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            return new AiError('La API key de Anthropic no es válida o no tiene permisos. Revise ANTHROPIC_API_KEY.');
        }
        if ($status === 404) {
            return new AiError('El modelo configurado en ANTHROPIC_MODEL no existe o no está disponible.');
        }
        if ($status === 429) {
            return new AiError('Se alcanzó el límite de uso de la IA. Intente de nuevo en unos minutos.');
        }
        if ($status === 400) {
            Log::error('[ia] solicitud rechazada', ['error' => (string) ($response->json('error.message') ?? '')]);

            return new AiError('La solicitud a la IA fue rechazada. Revise la configuración del modelo.');
        }

        return new AiError('El servicio de IA no está disponible en este momento. Intente más tarde.');
    }

    private static function isTimeout(ConnectionException $e): bool
    {
        $previous = $e->getPrevious();
        if ($previous instanceof ConnectException && ($previous->getHandlerContext()['errno'] ?? null) === 28) {
            return true;
        }

        return str_contains($e->getMessage(), 'cURL error 28') || str_contains(strtolower($e->getMessage()), 'timed out');
    }
}
