<?php

namespace Tests\Feature\Ai;

use App\Models\AuditLog;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Analysis\AiError;
use App\Services\Ai\Analysis\AnthropicAnalyzer;
use App\Services\Ai\Analysis\OpenAiAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Independent verifier probes for the configurable AI provider (secrecy, auth, validation, listing). */
class AiSettingsProbeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-probe-secreta-ABCD';

    private const ENV_KEY = 'sk-ant-env-key-WXYZ';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'assessments.ai_provider' => 'anthropic',
            'assessments.anthropic.api_key' => null,
            'assessments.anthropic.model' => 'claude-opus-5-5',
            'assessments.openai.api_key' => null,
            'assessments.openai.model' => null,
        ]);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function assertNothingLogged(string $secret): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($secret, $line, 'Secret reached the log');
        }
    }

    private function assertSecretNotInAudit(string $secret): void
    {
        foreach (AuditLog::all() as $row) {
            $this->assertStringNotContainsString($secret, json_encode($row->toArray()));
        }
    }

    public function test_saved_key_is_encrypted_and_never_in_page_session_or_audit(): void
    {
        $admin = $this->actingAsAdmin();
        $this->post('/admin/configuracion/ia', ['provider' => 'openai', 'openaiModel' => 'gpt-x', 'openaiApiKey' => self::KEY])
            ->assertSessionHasNoErrors();

        $stored = DB::table('app_settings')->where('key', 'ai.openai.api_key')->value('value');
        $this->assertStringStartsWith('v1:', $stored);
        $this->assertStringNotContainsString(self::KEY, json_encode(DB::table('app_settings')->get()));
        $this->assertSame(self::KEY, AiSettings::apiKey('openai'));

        $this->get('/admin/configuracion')->assertOk()->assertDontSee(self::KEY)->assertDontSee(substr(self::KEY, 0, 12));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->assertSecretNotInAudit(self::KEY);
        $this->assertNothingLogged(self::KEY);
    }

    public function test_rejected_save_does_not_echo_the_typed_key(): void
    {
        $this->actingAsAdmin();
        $typed = 'sk-typed-but-rejected-1234';
        // OpenAI active without a model: rejected after key validation.
        $r = $this->post('/admin/configuracion/ia', ['provider' => 'openai', 'openaiApiKey' => $typed]);
        $r->assertSessionHasErrors('openaiModel');
        $this->assertStringNotContainsString($typed, json_encode(session()->all()));
        $this->assertNull(DB::table('app_settings')->where('key', 'ai.openai.api_key')->value('value'));
        $this->assertSame('anthropic', AiSettings::provider());
    }

    public function test_refresh_with_typed_key_never_persists_it_and_keeps_active_model(): void
    {
        $admin = $this->actingAsAdmin();
        AiSettings::save(['provider' => 'anthropic', 'anthropicModel' => 'claude-opus-5-5', 'anthropicApiKey' => self::ENV_KEY], $admin->id);
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-9', 'created' => 9]]])]);

        $this->post('/admin/configuracion/ia/modelos/openai', ['apiKey' => self::KEY])->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::KEY));
        $this->assertNull(AiSettings::apiKey('openai'));
        $this->assertSame('anthropic', AiSettings::provider());
        $this->assertSame('claude-opus-5-5', AiSettings::model('anthropic'));
        $this->assertSame('', AiSettings::model('openai'));
        $this->assertStringNotContainsString(self::KEY, json_encode(DB::table('app_settings')->get()));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->get('/admin/configuracion')->assertDontSee(self::KEY);
    }

    public function test_every_settings_route_is_forbidden_for_limited_admins_and_needs_a_session(): void
    {
        $this->post('/admin/configuracion/ia', ['provider' => 'openai'])->assertRedirect();
        $this->post('/admin/configuracion/ia/modelos/anthropic', ['apiKey' => self::KEY])->assertRedirect();
        $viewer = $this->createAdmin('lector@x.co');
        $viewer->update(['access' => 'limited', 'permissions' => ['reports' => ['scope' => 'all']]]);
        $this->actingAsAdmin($viewer->fresh());
        Http::fake();
        $this->get('/admin/configuracion')->assertForbidden();
        $this->post('/admin/configuracion/ia', ['provider' => 'openai'])->assertForbidden();
        $this->post('/admin/configuracion/ia/modelos/anthropic', ['apiKey' => self::KEY])->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('app_settings')->count());
    }

    public function test_unknown_provider_route_is_404_and_sends_nothing(): void
    {
        $this->actingAsAdmin();
        Http::fake();
        $this->post('/admin/configuracion/ia/modelos/evil.example.com', ['apiKey' => self::KEY])->assertNotFound();
        $this->post('/admin/configuracion/ia/modelos/OPENAI', ['apiKey' => self::KEY])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_array_and_oversized_inputs_are_rejected_without_crashing(): void
    {
        $admin = $this->actingAsAdmin();
        $this->post('/admin/configuracion/ia', ['provider' => ['openai']])->assertSessionHasErrors('provider');
        $this->post('/admin/configuracion/ia', ['provider' => 'openai', 'openaiModel' => ['gpt-x'], 'openaiApiKey' => [self::KEY]])
            ->assertSessionHasErrors(['openaiApiKey', 'openaiModel']);
        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'anthropicApiKey' => str_repeat('a', 501)])
            ->assertSessionHasErrors('anthropicApiKey');
        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'anthropicApiKey' => 'sk-with space-123'])
            ->assertSessionHasErrors('anthropicApiKey');
        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'anthropicApiKey' => 'short'])
            ->assertSessionHasErrors('anthropicApiKey');
        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'anthropicApiKey' => self::KEY, 'anthropicModel' => str_repeat('m', 101)])
            ->assertSessionHasErrors('anthropicModel');
        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'anthropicApiKey' => self::KEY, 'anthropicModel' => '../etc'])
            ->assertSessionHasErrors('anthropicModel');
        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'anthropicApiKey' => self::KEY, 'removeOpenaiKey' => ['x']])
            ->assertSessionHasNoErrors();
        $this->assertSame(self::KEY, AiSettings::apiKey('anthropic'));
    }

    public function test_new_key_wins_over_remove_in_the_same_submit(): void
    {
        $admin = $this->actingAsAdmin();
        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'anthropicApiKey' => self::KEY, 'removeAnthropicKey' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(self::KEY, AiSettings::apiKey('anthropic'));
    }

    public function test_remove_saved_key_falls_back_to_env_key(): void
    {
        config(['assessments.anthropic.api_key' => self::ENV_KEY]);
        $admin = $this->actingAsAdmin();
        AiSettings::save(['provider' => 'anthropic', 'anthropicApiKey' => self::KEY], $admin->id);
        $this->assertSame(self::KEY, AiSettings::apiKey('anthropic'));

        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'removeAnthropicKey' => 'true'])->assertSessionHasNoErrors();
        $this->assertSame(self::ENV_KEY, AiSettings::apiKey('anthropic'));
        $this->assertTrue(AiSettings::configured());
        $this->get('/admin/configuracion')->assertDontSee(self::ENV_KEY)
            ->assertInertia(fn ($p) => $p->where('ai.providers.anthropic.keySource', 'env')->where('ai.providers.anthropic.keyHint', 'WXYZ'));
    }

    public function test_cannot_remove_the_only_key_of_the_active_provider(): void
    {
        $admin = $this->actingAsAdmin();
        AiSettings::save(['provider' => 'openai', 'openaiModel' => 'gpt-x', 'openaiApiKey' => self::KEY], $admin->id);
        $this->post('/admin/configuracion/ia', ['provider' => 'openai', 'removeOpenaiKey' => '1'])->assertSessionHasErrors('openaiApiKey');
        $this->assertSame(self::KEY, AiSettings::apiKey('openai'));
    }

    public function test_switching_to_a_provider_without_key_or_model_is_rejected(): void
    {
        $admin = $this->actingAsAdmin();
        AiSettings::save(['provider' => 'anthropic', 'anthropicApiKey' => self::KEY], $admin->id);
        $this->post('/admin/configuracion/ia', ['provider' => 'openai', 'openaiModel' => 'gpt-x'])->assertSessionHasErrors('openaiApiKey');
        $this->post('/admin/configuracion/ia', ['provider' => 'openai', 'openaiApiKey' => 'sk-open-12345'])->assertSessionHasErrors('openaiModel');
        $this->assertSame('anthropic', AiSettings::provider());
        $this->assertNull(AiSettings::apiKey('openai'));
    }

    public function test_undecryptable_saved_key_is_reported_not_configured_and_can_be_replaced(): void
    {
        config(['assessments.anthropic.api_key' => self::ENV_KEY]);
        $admin = $this->actingAsAdmin();
        DB::table('app_settings')->insert(['key' => 'ai.anthropic.api_key', 'value' => 'v1:AAAA:BBBB:CCCC', 'updated_at' => 1]);

        $this->assertNull(AiSettings::apiKey('anthropic'));
        $this->assertFalse(AiSettings::configured());
        $this->assertNull(AiSettings::analyzer());
        $this->get('/admin/configuracion')->assertOk()
            ->assertInertia(fn ($p) => $p->where('ai.providers.anthropic.configured', false)->where('ai.providers.anthropic.keyHint', null));

        $this->post('/admin/configuracion/ia', ['provider' => 'anthropic', 'removeAnthropicKey' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(self::ENV_KEY, AiSettings::apiKey('anthropic'));
    }

    public function test_env_only_install_keeps_working_with_anthropic(): void
    {
        config(['assessments.anthropic.api_key' => self::ENV_KEY]);
        $this->assertSame(0, DB::table('app_settings')->count());
        $this->assertTrue(AiSettings::configured());
        $this->assertInstanceOf(AnthropicAnalyzer::class, AiSettings::analyzer());
        $this->assertSame('claude-opus-5-5', AiSettings::analyzer()->model());
    }

    public function test_active_provider_drives_the_analyzer(): void
    {
        $admin = $this->actingAsAdmin();
        AiSettings::save(['provider' => 'openai', 'openaiModel' => 'gpt-x', 'openaiApiKey' => self::KEY], $admin->id);
        $this->assertInstanceOf(OpenAiAnalyzer::class, AiSettings::analyzer());
        $this->assertSame('gpt-x', AiSettings::analyzer()->model());
    }

    public function test_anthropic_model_list_follows_pagination_newest_first(): void
    {
        $this->actingAsAdmin();
        Http::fake(['api.anthropic.com/v1/models*' => Http::sequence()
            ->push(['data' => [['id' => 'claude-old', 'display_name' => 'Old', 'created_at' => '2024-01-01T00:00:00Z']], 'has_more' => true, 'last_id' => 'claude-old'])
            ->push(['data' => [['id' => 'claude-new', 'display_name' => 'New', 'created_at' => '2026-01-01T00:00:00Z']], 'has_more' => false, 'last_id' => 'claude-new']),
        ]);
        $this->post('/admin/configuracion/ia/modelos/anthropic', ['apiKey' => self::KEY])->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'after_id=claude-old') && $r->hasHeader('x-api-key', self::KEY));
        $models = AiSettings::forUi()['providers']['anthropic']['models'];
        $this->assertSame(['claude-new', 'claude-old'], array_column($models, 'id'));
    }

    public function test_openai_list_keeps_only_text_models(): void
    {
        $this->actingAsAdmin();
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => array_map(
            fn ($id) => ['id' => $id, 'created' => 1],
            ['gpt-5', 'o3-mini', 'gpt-4o-audio-preview', 'text-embedding-3-large', 'dall-e-3', 'whisper-1', 'gpt-image-1', 'tts-1', 'omni-moderation-latest'],
        )])]);
        $this->post('/admin/configuracion/ia/modelos/openai', ['apiKey' => self::KEY])->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(['gpt-5', 'o3-mini'], array_column(AiSettings::forUi()['providers']['openai']['models'], 'id'));
    }

    public function test_model_list_errors_keep_the_previous_list_and_do_not_leak_the_key(): void
    {
        $this->actingAsAdmin();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::KEY]], 401)]);
        $this->post('/admin/configuracion/ia/modelos/openai', ['apiKey' => self::KEY])
            ->assertSessionHas('error', 'La API key de OpenAI no es válida o no tiene permisos.');
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->assertNull(DB::table('app_settings')->where('key', 'ai.openai.models')->value('value'));

        Http::fake(fn () => throw new ConnectionException('cURL error 6'));
        $this->post('/admin/configuracion/ia/modelos/anthropic', ['apiKey' => self::KEY])->assertSessionHas('error');
        $this->assertNothingLogged(self::KEY);
    }

    public function test_refresh_without_any_key_asks_for_one(): void
    {
        $this->actingAsAdmin();
        Http::fake();
        $this->post('/admin/configuracion/ia/modelos/openai')->assertSessionHas('error', 'Escriba o guarde primero la API key de OpenAI.');
        Http::assertNothingSent();
    }

    public function test_openai_request_matches_the_responses_api_with_strict_schema(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']])]);
        try {
            (new OpenAiAnalyzer(self::KEY, 'gpt-x', 30000))->analyze('hola');
        } catch (\Throwable) {
        }
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses'
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $r['model'] === 'gpt-x'
            && $r['text']['format']['type'] === 'json_schema'
            && $r['text']['format']['strict'] === true
            && is_array($r['text']['format']['schema'])
            && $r['input'][0]['role'] === 'system'
            && $r['input'][1]['content'] === 'hola');
    }

    /** A pasted key with a control character or line break is rejected, and never logged. */
    public function test_key_with_control_character_is_never_logged(): void
    {
        $bad = "sk-ctrl\x01secret-KEY9";
        $admin = $this->actingAsAdmin();
        Http::fake();

        $saved = AiSettings::save(['provider' => 'openai', 'openaiModel' => 'gpt-x', 'openaiApiKey' => $bad], $admin->id);
        $this->assertSame(['openaiApiKey' => 'La API key no es válida.'], $saved['errors'] ?? null);

        $this->post('/admin/configuracion/ia/modelos/openai', ['apiKey' => "sk-linea\nsecret-KEY9"])
            ->assertSessionHas('error', 'La API key de OpenAI no es válida: revise que no tenga espacios ni saltos de línea.');
        Http::assertNothingSent();

        // A bad key can still come from .env: the HTTP error is logged by class only.
        config(['assessments.ai_provider' => 'openai', 'assessments.openai.api_key' => $bad, 'assessments.openai.model' => 'gpt-x']);
        try {
            AiSettings::analyzer()->analyze('hola');
            $this->fail('Expected AiError');
        } catch (AiError $e) {
            $this->assertSame('Ocurrió un error inesperado al analizar con IA.', $e->getMessage());
        }
        $this->assertNotEmpty($this->logged);
        $this->assertNothingLogged('secret-KEY9');
    }
}
