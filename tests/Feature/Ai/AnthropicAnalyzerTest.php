<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiStore;
use App\Services\Ai\Analysis\AiError;
use App\Services\Ai\Analysis\AnalysisNormalizer;
use App\Services\Ai\Analysis\AnthropicAnalyzer;
use App\Services\Ai\Analysis\Prompts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** The Messages API request (shape, headers) and the error translation of src/lib/server/ai/client.ts. */
class AnthropicAnalyzerTest extends TestCase
{
    private const URL = 'https://api.anthropic.com/v1/messages?beta=true';

    /** Replaces every previous stub (Http::fake() keeps the first matching one). */
    private function stubApi(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private static function message(array $output, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                ['type' => 'text', 'text' => json_encode($output)],
            ],
            'stop_reason' => $stopReason,
        ];
    }

    private function analyzer(string $model = 'claude-opus-5-5'): AnthropicAnalyzer
    {
        return new AnthropicAnalyzer('sk-ant-prueba', $model, 30000);
    }

    private function messageFor(string $error): string
    {
        try {
            $this->analyzer()->analyze('x');
        } catch (AiError $e) {
            return $e->getMessage();
        }
        $this->fail("Expected AiError ({$error})");
    }

    public function test_sends_the_same_request_as_the_original_client(): void
    {
        $this->stubApi([self::URL => Http::response(self::message(SeedsOpenAnswersResult::RESULT))]);

        $out = $this->analyzer()->analyze('Pregunta: ¿Qué mejoraría?');

        $this->assertSame(SeedsOpenAnswersResult::RESULT, $out);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->method() === 'POST'
                && $r->url() === self::URL
                && $r->header('x-api-key') === ['sk-ant-prueba']
                && $r->header('anthropic-version') === ['2023-06-01']
                && $r->header('anthropic-beta') === ['server-side-fallback-2026-07-01,structured-outputs-2025-12-15']
                && $body['model'] === 'claude-opus-5-5'
                && $body['max_tokens'] === 16000
                && $body['system'] === Prompts::SYSTEM
                && $body['messages'] === [['role' => 'user', 'content' => 'Pregunta: ¿Qué mejoraría?']]
                && $body['output_config']['effort'] === 'medium'
                && $body['output_config']['format']['type'] === 'json_schema'
                && $body['output_config']['format']['schema']['required'] === ['summary', 'themes', 'highlights', 'concerns', 'quotes']
                && $body['output_config']['format']['schema']['additionalProperties'] === false
                && $body['fallbacks'] === 'default'
                && ! array_key_exists('betas', $body)
                && ! array_key_exists('temperature', $body);
        });
    }

    public function test_models_without_server_fallback_do_not_send_it(): void
    {
        $this->stubApi([self::URL => Http::response(self::message(SeedsOpenAnswersResult::RESULT))]);
        $this->analyzer('claude-haiku-5-5')->analyze('x');
        Http::assertSent(fn (Request $r) => $r->header('anthropic-beta') === ['structured-outputs-2025-12-15']
            && ! array_key_exists('fallbacks', $r->data())
            && $r->data()['model'] === 'claude-haiku-5-5');
    }

    public function test_refusal_and_incomplete_outputs_become_spanish_errors(): void
    {
        $this->stubApi([self::URL => Http::response(self::message(SeedsOpenAnswersResult::RESULT, 'refusal'))]);
        $this->assertSame('La IA no pudo analizar este contenido. Revise las respuestas o intente más tarde.', $this->messageFor('refusal'));

        $this->stubApi([self::URL => Http::response(self::message(SeedsOpenAnswersResult::RESULT, 'max_tokens'))]);
        $this->assertSame('La IA devolvió una respuesta incompleta. Intente de nuevo.', $this->messageFor('max_tokens'));

        $this->stubApi([self::URL => Http::response(self::message(['summary' => 'sin temas']))]);
        $this->assertSame('La IA devolvió una respuesta incompleta. Intente de nuevo.', $this->messageFor('shape'));
    }

    public function test_translates_http_errors_to_spanish_messages(): void
    {
        $cases = [
            401 => 'La API key de Anthropic no es válida o no tiene permisos. Revise ANTHROPIC_API_KEY.',
            403 => 'La API key de Anthropic no es válida o no tiene permisos. Revise ANTHROPIC_API_KEY.',
            404 => 'El modelo configurado en ANTHROPIC_MODEL no existe o no está disponible.',
            429 => 'Se alcanzó el límite de uso de la IA. Intente de nuevo en unos minutos.',
            400 => 'La solicitud a la IA fue rechazada. Revise la configuración del modelo.',
            529 => 'El servicio de IA no está disponible en este momento. Intente más tarde.',
        ];
        foreach ($cases as $status => $expected) {
            $this->stubApi([self::URL => Http::response(['type' => 'error', 'error' => ['message' => 'x']], $status)]);
            $this->assertSame($expected, $this->messageFor((string) $status), "HTTP {$status}");
        }
    }

    public function test_retries_rate_limits_and_server_errors_twice_like_the_sdk(): void
    {
        $this->stubApi([self::URL => Http::sequence()
            ->push(['error' => []], 529)
            ->push(['error' => []], 429)
            ->push(self::message(SeedsOpenAnswersResult::RESULT))]);
        $this->assertSame(SeedsOpenAnswersResult::RESULT, $this->analyzer()->analyze('x'));
        Http::assertSentCount(3);

        $this->stubApi([self::URL => Http::response(['error' => []], 400)]);
        $this->messageFor('400');
        Http::assertSentCount(1);
    }

    public function test_connection_errors_and_timeouts(): void
    {
        $this->stubApi([self::URL => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds')]);
        $this->assertStringContainsString('tardó demasiado', $this->messageFor('timeout'));

        $this->stubApi([self::URL => Http::failedConnection('cURL error 6: Could not resolve host')]);
        $this->assertStringContainsString('No se pudo conectar', $this->messageFor('connection'));
    }

    public function test_never_writes_the_api_key_to_the_log(): void
    {
        $logged = '';
        \Illuminate\Support\Facades\Log::listen(function ($e) use (&$logged) {
            $logged .= $e->message.json_encode($e->context);
        });
        $this->stubApi([self::URL => Http::response(['type' => 'error', 'error' => ['message' => 'bad']], 400)]);
        $this->messageFor('400');
        $this->assertStringNotContainsString('sk-ant-prueba', $logged);
    }

    public function test_cuts_the_model_output_to_the_stored_limits(): void
    {
        $long = AnalysisNormalizer::normalize([
            'summary' => str_repeat('s', 5000),
            'themes' => array_map(fn ($i) => ['name' => "T{$i}", 'description' => '', 'frequency' => 'baja', 'approxCount' => -3], range(0, 19)),
            'highlights' => [],
            'concerns' => [],
            'quotes' => array_fill(0, 20, 'cita'),
        ]);
        $this->assertLessThanOrEqual(4000, mb_strlen($long['summary']));
        $this->assertStringEndsWith('…', $long['summary']);
        $this->assertCount(15, $long['themes']);
        $this->assertSame(0, $long['themes'][0]['approxCount']);
        $this->assertCount(12, $long['quotes']);
        $this->assertTrue(AiStore::isValidResult($long));

        $empty = AnalysisNormalizer::normalize([
            'summary' => '  ', 'themes' => [['name' => ' ', 'description' => ' d ', 'frequency' => 'media', 'approxCount' => 2.0]],
            'highlights' => [], 'concerns' => [], 'quotes' => [],
        ]);
        $this->assertSame('Sin resumen.', $empty['summary']);
        $this->assertSame(['name' => 'Tema', 'description' => 'd', 'frequency' => 'media', 'approxCount' => 2], $empty['themes'][0]);
        $this->assertNull(AnalysisNormalizer::normalize(['summary' => 'x', 'themes' => [['name' => 'a', 'description' => '', 'frequency' => 'otra', 'approxCount' => 1]], 'highlights' => [], 'concerns' => [], 'quotes' => []]));
    }
}

/** Shared fixture (kept here so the analyzer test needs no database). */
final class SeedsOpenAnswersResult
{
    public const RESULT = [
        'summary' => 'Resumen de prueba.',
        'themes' => [['name' => 'Tiempo', 'description' => 'Falta de tiempo.', 'frequency' => 'alta', 'approxCount' => 2]],
        'highlights' => ['Compromiso'],
        'concerns' => ['Carga laboral'],
        'quotes' => ['No tengo tiempo'],
    ];
}
