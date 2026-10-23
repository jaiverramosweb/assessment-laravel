<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Current model list of each provider, read from its models endpoint with an API key:
 * - Anthropic: GET /v1/models (paginated with has_more / after_id).
 * - OpenAI: GET /v1/models, keeping only text-generation models (no embeddings, audio, images...).
 * Models come back newest first as {id, name, created (unix seconds)}.
 */
final class AiModels
{
    /** Suggestions shown for Anthropic before the list is fetched. */
    public const ANTHROPIC_SUGGESTIONS = [
        ['id' => 'claude-opus-5-5', 'name' => 'Claude Opus 5.5', 'created' => null],
        ['id' => 'claude-sonnet-5-5', 'name' => 'Claude Sonnet 5.5', 'created' => null],
        ['id' => 'claude-haiku-5-5', 'name' => 'Claude Haiku 5.5', 'created' => null],
        ['id' => 'claude-fable-5-1', 'name' => 'Claude Fable 5.1', 'created' => null],
    ];

    private const OPENAI_TEXT = '/^(gpt-|chatgpt-|o\d)/';

    private const OPENAI_NOT_TEXT = '/(audio|realtime|tts|transcribe|image|search|embedding|moderation|instruct|dall-e|whisper|computer-use|diarize)/';

    private const MAX_PAGES = 5;

    /**
     * @return array{ok: true, models: list<array{id: string, name: string, created: ?int}>}|array{ok: false, error: string}
     */
    public static function fetch(string $provider, #[\SensitiveParameter] string $apiKey): array
    {
        $label = $provider === 'openai' ? 'OpenAI' : 'Anthropic';
        try {
            $models = $provider === 'openai' ? self::openAi($apiKey) : self::anthropic($apiKey);
        } catch (ModelListError $e) {
            return ['ok' => false, 'error' => str_replace(':proveedor', $label, $e->getMessage())];
        } catch (ConnectionException) {
            return ['ok' => false, 'error' => "No se pudo conectar con {$label}. Revise la conexión a internet."];
        } catch (Throwable $e) {
            // Only the class: the message of an HTTP client error can contain the key (a header value).
            Log::error('[ia] error al listar modelos', ['proveedor' => $provider, 'error' => $e::class]);

            return ['ok' => false, 'error' => "No se pudo leer la lista de modelos de {$label}."];
        }

        usort($models, fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        return ['ok' => true, 'models' => $models];
    }

    private static function anthropic(string $apiKey): array
    {
        $models = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->timeout(20)
                ->get('https://api.anthropic.com/v1/models', ['limit' => 1000, ...($after ? ['after_id' => $after] : [])]);
            self::check($response->status());
            foreach ((array) $response->json('data') as $m) {
                if (is_string($m['id'] ?? null)) {
                    $created = is_string($m['created_at'] ?? null) ? strtotime($m['created_at']) : false;
                    $models[] = [
                        'id' => $m['id'],
                        'name' => is_string($m['display_name'] ?? null) ? $m['display_name'] : $m['id'],
                        'created' => $created === false ? null : $created,
                    ];
                }
            }
            $after = $response->json('last_id');
            if (! $response->json('has_more') || ! is_string($after)) {
                break;
            }
        }

        return $models;
    }

    private static function openAi(string $apiKey): array
    {
        $response = Http::withToken($apiKey)->acceptJson()->timeout(20)->get('https://api.openai.com/v1/models');
        self::check($response->status());

        $models = [];
        foreach ((array) $response->json('data') as $m) {
            $id = $m['id'] ?? null;
            if (is_string($id) && preg_match(self::OPENAI_TEXT, $id) && ! preg_match(self::OPENAI_NOT_TEXT, $id)) {
                $models[] = ['id' => $id, 'name' => $id, 'created' => is_int($m['created'] ?? null) ? $m['created'] : null];
            }
        }

        return $models;
    }

    private static function check(int $status): void
    {
        if ($status === 401 || $status === 403) {
            throw new ModelListError('La API key de :proveedor no es válida o no tiene permisos.');
        }
        if ($status === 429) {
            throw new ModelListError('Se alcanzó el límite de uso de :proveedor. Intente de nuevo en unos minutos.');
        }
        if ($status < 200 || $status >= 300) {
            throw new ModelListError('El servicio de :proveedor no está disponible en este momento. Intente más tarde.');
        }
    }
}
