<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Analysis\AiError;
use App\Services\Ai\Analysis\AnalysisNormalizer;
use App\Services\Ai\Analysis\OpenAiAnalyzer;
use App\Services\Ai\Analysis\Prompts;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** The OpenAI Responses API request (strict JSON schema) and its error translation. */
class OpenAiAnalyzerTest extends TestCase
{
    private const URL = 'https://api.openai.com/v1/responses';

    /** Replaces every previous stub (Http::fake() keeps the first matching one). */
    private function stubApi(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private static function response(array $content, string $status = 'completed', ?array $incomplete = null): array
    {
        return [
            'id' => 'resp_1',
            'object' => 'response',
            'status' => $status,
            'incomplete_details' => $incomplete,
            'model' => 'gpt-prueba',
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => $content],
            ],
        ];
    }

    private static function text(array $output): array
    {
        return [['type' => 'output_text', 'text' => json_encode($output), 'annotations' => []]];
    }

    private function analyzer(): OpenAiAnalyzer
    {
        return new OpenAiAnalyzer('sk-openai-prueba', 'gpt-prueba', 30000);
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

    public function test_sends_a_strict_json_schema_request_and_parses_the_output(): void
    {
        $this->stubApi([self::URL => Http::response(self::response(self::text(SeedsOpenAnswersResult::RESULT)))]);

        $out = $this->analyzer()->analyze('Pregunta: ¿Qué mejoraría?');

        $this->assertSame(SeedsOpenAnswersResult::RESULT, $out);
        $this->assertSame('gpt-prueba', $this->analyzer()->model());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->method() === 'POST'
                && $r->url() === self::URL
                && $r->header('Authorization') === ['Bearer sk-openai-prueba']
                && $body['model'] === 'gpt-prueba'
                && $body['input'] === [
                    ['role' => 'system', 'content' => Prompts::SYSTEM],
                    ['role' => 'user', 'content' => 'Pregunta: ¿Qué mejoraría?'],
                ]
                && $body['max_output_tokens'] === 16000
                && $body['text']['format']['type'] === 'json_schema'
                && $body['text']['format']['strict'] === true
                && $body['text']['format']['schema'] === AnalysisNormalizer::outputSchema()
                && is_string($body['text']['format']['name']);
        });
    }

    public function test_translates_errors_to_spanish(): void
    {
        $this->stubApi([self::URL => Http::response(['error' => ['message' => 'bad key']], 401)]);
        $this->assertSame('La API key de OpenAI no es válida o no tiene permisos. Revísela en Configuración.', $this->messageFor('401'));

        $this->stubApi([self::URL => Http::response(['error' => ['message' => 'no model']], 404)]);
        $this->assertSame('El modelo de OpenAI elegido no existe o su cuenta no tiene acceso. Elija otro en Configuración.', $this->messageFor('404'));

        $this->stubApi([self::URL => Http::response(['error' => ['code' => 'insufficient_quota']], 429)]);
        $this->assertSame('La cuenta de OpenAI no tiene saldo o alcanzó su límite de uso. Revise la facturación de OpenAI.', $this->messageFor('quota'));
        Http::assertSentCount(1);

        $this->stubApi([self::URL => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);
        $this->assertSame('Se alcanzó el límite de uso de la IA. Intente de nuevo en unos minutos.', $this->messageFor('429'));
        Http::assertSentCount(3);

        $this->stubApi([self::URL => Http::response(['error' => ['message' => 'bad']], 400)]);
        $this->assertSame('La solicitud a la IA fue rechazada. Revise la configuración del modelo.', $this->messageFor('400'));

        $this->stubApi([self::URL => Http::response([], 503)]);
        $this->assertSame('El servicio de IA no está disponible en este momento. Intente más tarde.', $this->messageFor('503'));
    }

    public function test_refusals_and_incomplete_responses(): void
    {
        $this->stubApi([self::URL => Http::response(self::response([['type' => 'refusal', 'refusal' => 'No puedo']]))]);
        $this->assertSame('La IA no pudo analizar este contenido. Revise las respuestas o intente más tarde.', $this->messageFor('refusal'));

        $this->stubApi([self::URL => Http::response(self::response([], 'incomplete', ['reason' => 'content_filter']))]);
        $this->assertSame('La IA no pudo analizar este contenido. Revise las respuestas o intente más tarde.', $this->messageFor('filter'));

        $this->stubApi([self::URL => Http::response(self::response(self::text(SeedsOpenAnswersResult::RESULT), 'incomplete', ['reason' => 'max_output_tokens']))]);
        $this->assertSame('La IA devolvió una respuesta incompleta. Intente de nuevo.', $this->messageFor('max'));

        $this->stubApi([self::URL => Http::response(self::response(self::text(['otra' => 'cosa'])))]);
        $this->assertSame('La IA devolvió una respuesta incompleta. Intente de nuevo.', $this->messageFor('shape'));
    }
}
