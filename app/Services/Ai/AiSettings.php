<?php

namespace App\Services\Ai;

use App\Security\Audit;
use App\Security\SecretBox;
use App\Services\Ai\Analysis\Analyzer;
use App\Services\Ai\Analysis\AnthropicAnalyzer;
use App\Services\Ai\Analysis\OpenAiAnalyzer;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Which AI provider and model the analysis uses, and each provider's API key. Values saved from
 * the "Configuración" screen (table app_settings) win over config/assessments.php (.env), so an
 * install that only has ANTHROPIC_API_KEY keeps working. API keys are stored encrypted with
 * SecretBox and never leave the server: the UI only gets their last 4 characters.
 */
final class AiSettings
{
    public const PROVIDERS = ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI'];

    public const NOT_CONFIGURED = 'Configure la IA (proveedor, modelo y API key) en Configuración para usarla.';

    private const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/';

    private const LABELS = ['anthropic' => 'Anthropic', 'openai' => 'OpenAI'];

    /**
     * An API key is printable ASCII without spaces (10 to 500 chars). Anything else (line breaks,
     * control characters) would be rejected as an HTTP header, and that error echoes the value.
     */
    public static function validKey(string $key): bool
    {
        return preg_match('/^[\x21-\x7E]{10,500}$/', $key) === 1;
    }

    /** Short provider name for messages ("Anthropic", "OpenAI"). */
    public static function label(string $provider): string
    {
        return self::LABELS[$provider] ?? $provider;
    }

    public static function provider(): string
    {
        $saved = self::get('ai.provider');
        $provider = $saved ?? (string) config('assessments.ai_provider', 'anthropic');

        return array_key_exists($provider, self::PROVIDERS) ? $provider : 'anthropic';
    }

    /** Model of the given provider (default: the active one). Empty when none is set. */
    public static function model(?string $provider = null): string
    {
        $provider ??= self::provider();

        return self::get("ai.{$provider}.model") ?? (string) config("assessments.{$provider}.model");
    }

    /** API key of the provider: saved (decrypted) or from .env; null when there is none. */
    public static function apiKey(string $provider): ?string
    {
        $payload = self::get("ai.{$provider}.api_key");
        if ($payload !== null) {
            $key = SecretBox::decrypt($payload);
            if ($key === null) {
                Log::warning('[ia] no se pudo descifrar la API key guardada', ['proveedor' => $provider]);
            }

            return $key;
        }
        $env = (string) config("assessments.{$provider}.api_key");

        return $env === '' ? null : $env;
    }

    public static function timeoutMs(): int
    {
        return (int) config('assessments.anthropic.timeout_ms');
    }

    /** Is the active provider ready (API key and model)? */
    public static function configured(): bool
    {
        $provider = self::provider();

        return self::apiKey($provider) !== null && self::model($provider) !== '';
    }

    /** Client of the active provider, or null when it is not configured. */
    public static function analyzer(): ?Analyzer
    {
        $provider = self::provider();
        $key = self::apiKey($provider);
        $model = self::model($provider);
        if ($key === null || $model === '') {
            return null;
        }

        return $provider === 'openai'
            ? new OpenAiAnalyzer($key, $model, self::timeoutMs())
            : new AnthropicAnalyzer($key, $model, self::timeoutMs());
    }

    /**
     * Data for the "Configuración" screen. Never includes an API key.
     *
     * @return array{provider: string, providers: array<string, array{configured: bool, keySource: ?string, keyHint: ?string, model: string, models: list<array>, modelsFetchedAt: ?int}>}
     */
    public static function forUi(): array
    {
        $providers = [];
        foreach (array_keys(self::PROVIDERS) as $p) {
            $key = self::apiKey($p);
            $cache = json_decode((string) self::get("ai.{$p}.models"), true);
            $providers[$p] = [
                'configured' => $key !== null && self::model($p) !== '',
                'keySource' => $key === null ? null : (self::get("ai.{$p}.api_key") !== null ? 'db' : 'env'),
                'keyHint' => $key === null ? null : mb_substr($key, -4),
                'model' => self::model($p),
                'models' => is_array($cache['models'] ?? null) ? $cache['models'] : [],
                'modelsFetchedAt' => is_int($cache['fetchedAt'] ?? null) ? $cache['fetchedAt'] : null,
            ];
        }

        return ['provider' => self::provider(), 'providers' => $providers];
    }

    /**
     * Saves the screen: `provider`, `{p}Model`, `{p}ApiKey` (empty = keep the saved one) and
     * `remove{P}Key`. The active provider must end up with a key and a model.
     *
     * @return array{ok: true}|array{ok: false, errors: array<string, string>}
     */
    public static function save(array $input, string $actorId, ?string $ip = null): array
    {
        $provider = $input['provider'] ?? null;
        if (! is_string($provider) || ! array_key_exists($provider, self::PROVIDERS)) {
            return ['ok' => false, 'errors' => ['provider' => 'Elija un proveedor de IA.']];
        }

        $errors = [];
        $models = [];
        $keys = [];
        $newKeys = [];
        foreach (array_keys(self::PROVIDERS) as $p) {
            $model = trim((string) (is_string($input["{$p}Model"] ?? null) ? $input["{$p}Model"] : ''));
            if ($model !== '' && ! preg_match(self::MODEL_PATTERN, $model)) {
                $errors["{$p}Model"] = 'El nombre del modelo no es válido.';
            }
            $models[$p] = $model;

            $key = trim((string) (is_string($input["{$p}ApiKey"] ?? null) ? $input["{$p}ApiKey"] : ''));
            if ($key !== '' && ! self::validKey($key)) {
                $errors["{$p}ApiKey"] = 'La API key no es válida.';
            }
            $remove = filter_var($input['remove'.ucfirst($p).'Key'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $keys[$p] = $key !== '' ? 'guardada' : ($remove ? 'eliminada' : null);
            $newKeys[$p] = $key;
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        // The active provider must be usable after saving (a saved, new or .env key, and a model).
        $hasKey = $keys[$provider] === 'guardada'
            || ($keys[$provider] === null && self::apiKey($provider) !== null)
            || ($keys[$provider] === 'eliminada' && (string) config("assessments.{$provider}.api_key") !== '');
        $label = self::LABELS[$provider];
        if (! $hasKey) {
            $errors["{$provider}ApiKey"] = "Para usar {$label} guarde su API key.";
        }
        if (($models[$provider] !== '' ? $models[$provider] : self::model($provider)) === '') {
            $errors["{$provider}Model"] = "Para usar {$label} elija un modelo.";
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        DB::transaction(function () use ($provider, $models, $keys, $newKeys) {
            self::put('ai.provider', $provider);
            foreach ($models as $p => $model) {
                if ($model !== '') {
                    self::put("ai.{$p}.model", $model);
                }
                if ($keys[$p] === 'guardada') {
                    self::put("ai.{$p}.api_key", SecretBox::encrypt($newKeys[$p]));
                } elseif ($keys[$p] === 'eliminada') {
                    DB::table('app_settings')->where('key', "ai.{$p}.api_key")->delete();
                }
            }
        });
        Audit::log($actorId, 'ia.configuracion_cambiada', 'configuracion', 'ia', [
            'proveedor' => $provider,
            'modelos' => array_filter($models),
            'claves' => $keys,
        ], $ip);

        return ['ok' => true];
    }

    /** Remembers the model list fetched from a provider (shown until the next refresh). */
    public static function rememberModels(string $provider, array $models): void
    {
        self::put("ai.{$provider}.models", json_encode(['fetchedAt' => Clock::nowMs(), 'models' => $models]));
    }

    private static function get(string $key): ?string
    {
        $value = DB::table('app_settings')->where('key', $key)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function put(string $key, string $value): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => Clock::nowMs()]);
    }
}
