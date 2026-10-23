<?php

namespace App\Services\Ai\Analysis;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenAI client over the Responses API, with the same prompt, output schema and Spanish errors
 * as AnthropicAnalyzer. Only used on the server: the key never reaches the browser and is never
 * logged.
 * - Structured output: `text.format` json_schema with `strict: true` (the schema already has
 *   every property required and no additional properties, as strict mode demands).
 * - Retries like AnthropicAnalyzer (408, 409, 429, 5xx and connection errors), except a 429
 *   for lack of credit (`insufficient_quota`), which does not fix itself.
 */
final class OpenAiAnalyzer implements Analyzer
{
    public const URL = 'https://api.openai.com/v1/responses';

    private const MAX_RETRIES = 2;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutMs,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function analyze(string $userPrompt): array
    {
        $body = [
            'model' => $this->model,
            'input' => [
                ['role' => 'system', 'content' => Prompts::SYSTEM],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_output_tokens' => 16000,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'open_answers_analysis',
                    'strict' => true,
                    'schema' => AnalysisNormalizer::outputSchema(),
                ],
            ],
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(max(1, $this->timeoutMs) / 1000)
                ->retry(
                    self::MAX_RETRIES + 1,
                    fn (int $attempt) => min(8000, 500 * 2 ** ($attempt - 1)),
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && self::retryable($e->response)),
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

        $data = $response->json();
        $refused = new AiError('La IA no pudo analizar este contenido. Revise las respuestas o intente más tarde.');
        if (($data['status'] ?? null) === 'incomplete') {
            throw ($data['incomplete_details']['reason'] ?? null) === 'content_filter'
                ? $refused
                : new AiError('La IA devolvió una respuesta incompleta. Intente de nuevo.');
        }

        foreach (is_array($data['output'] ?? null) ? $data['output'] : [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    throw $refused;
                }
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $result = AnalysisNormalizer::normalize(json_decode($part['text'], true));
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        throw new AiError('La IA devolvió una respuesta incompleta. Intente de nuevo.');
    }

    private static function retryable(Response $response): bool
    {
        $status = $response->status();
        if ($status === 429) {
            return $response->json('error.code') !== 'insufficient_quota';
        }

        return in_array($status, [408, 409], true) || $status >= 500;
    }

    /** Translates API statuses to clear Spanish messages. */
    private static function statusError(Response $response): AiError
    {
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            return new AiError('La API key de OpenAI no es válida o no tiene permisos. Revísela en Configuración.');
        }
        if ($status === 404) {
            return new AiError('El modelo de OpenAI elegido no existe o su cuenta no tiene acceso. Elija otro en Configuración.');
        }
        if ($status === 429) {
            return $response->json('error.code') === 'insufficient_quota'
                ? new AiError('La cuenta de OpenAI no tiene saldo o alcanzó su límite de uso. Revise la facturación de OpenAI.')
                : new AiError('Se alcanzó el límite de uso de la IA. Intente de nuevo en unos minutos.');
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
