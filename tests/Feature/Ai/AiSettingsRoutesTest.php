<?php

namespace Tests\Feature\Ai;

use App\Models\AdminUser;
use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** "Configuración" screen: AI provider, models, API keys and the live model lists. */
class AiSettingsRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const OPENAI_KEY = 'sk-openai-secreta-9876';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'assessments.ai_provider' => 'anthropic',
            'assessments.anthropic.api_key' => null,
            'assessments.anthropic.model' => 'claude-opus-5-5',
            'assessments.openai.api_key' => null,
            'assessments.openai.model' => null,
        ]);
    }

    public function test_requires_a_full_access_admin(): void
    {
        $this->get('/admin/configuracion')->assertRedirect();

        $viewer = $this->createAdmin('lector@x.co');
        $viewer->update(['access' => 'limited', 'permissions' => ['reports' => ['scope' => 'all']]]);
        $this->actingAsAdmin($viewer->fresh());
        $this->get('/admin/configuracion')->assertForbidden();
        $this->post('/admin/configuracion/ia', ['provider' => 'openai', 'openaiApiKey' => self::OPENAI_KEY, 'openaiModel' => 'gpt-x'])->assertForbidden();
        $this->post('/admin/configuracion/ia/modelos/openai')->assertForbidden();
        $this->assertSame('anthropic', AiSettings::provider());
    }

    public function test_page_shows_settings_without_any_key(): void
    {
        $this->actingAsAdmin();
        AiSettings::save(['provider' => 'openai', 'openaiModel' => 'gpt-prueba', 'openaiApiKey' => self::OPENAI_KEY], AdminUser::first()->id);

        $this->get('/admin/configuracion')
            ->assertOk()
            ->assertDontSee(self::OPENAI_KEY)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Settings/Index')
                ->where('ai.provider', 'openai')
                ->where('ai.providers.openai.keyHint', '9876')
                ->where('ai.providers.openai.model', 'gpt-prueba')
                ->where('ai.providers.anthropic.configured', false)
                ->has('labels.openai')
                ->has('suggestions.anthropic'));
    }

    public function test_save_switches_the_provider(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/configuracion/ia', [
            'provider' => 'openai', 'openaiModel' => 'gpt-prueba', 'openaiApiKey' => self::OPENAI_KEY, 'anthropicModel' => 'claude-opus-5-5',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Configuración de IA guardada. Los próximos análisis usarán OpenAI (gpt-prueba).');

        $this->assertSame('openai', AiSettings::provider());
        $this->assertSame(self::OPENAI_KEY, AiSettings::apiKey('openai'));
    }

    public function test_save_validation_errors_change_nothing(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/configuracion/ia', ['provider' => 'openai'])
            ->assertSessionHasErrors(['openaiApiKey' => 'Para usar OpenAI guarde su API key.']);

        $this->assertSame('anthropic', AiSettings::provider());
    }

    public function test_refresh_lists_openai_text_models_newest_first_with_a_typed_key(): void
    {
        $this->actingAsAdmin();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['object' => 'list', 'data' => [
            ['id' => 'gpt-viejo', 'object' => 'model', 'created' => 100, 'owned_by' => 'openai'],
            ['id' => 'gpt-nuevo', 'object' => 'model', 'created' => 300, 'owned_by' => 'openai'],
            ['id' => 'o9-mini', 'object' => 'model', 'created' => 200, 'owned_by' => 'openai'],
            ['id' => 'text-embedding-3-large', 'object' => 'model', 'created' => 400, 'owned_by' => 'openai'],
            ['id' => 'gpt-nuevo-realtime', 'object' => 'model', 'created' => 500, 'owned_by' => 'openai'],
            ['id' => 'gpt-image-2', 'object' => 'model', 'created' => 600, 'owned_by' => 'openai'],
            ['id' => 'whisper-1', 'object' => 'model', 'created' => 700, 'owned_by' => 'openai'],
        ]])]);

        $this->post('/admin/configuracion/ia/modelos/openai', ['apiKey' => self::OPENAI_KEY])
            ->assertRedirect()
            ->assertSessionHas('success', 'Lista de modelos de OpenAI actualizada: 3 modelos.');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->header('Authorization') === ['Bearer '.self::OPENAI_KEY]);
        $this->assertSame(['gpt-nuevo', 'o9-mini', 'gpt-viejo'], array_column(AiSettings::forUi()['providers']['openai']['models'], 'id'));
        $this->assertNull(AiSettings::apiKey('openai'), 'A typed key is only used to list, never saved.');
    }

    public function test_refresh_lists_anthropic_models_across_pages_with_the_saved_key(): void
    {
        $this->actingAsAdmin();
        config(['assessments.anthropic.api_key' => 'sk-ant-env-1234']);
        Http::fake([
            'https://api.anthropic.com/v1/models?limit=1000' => Http::response([
                'data' => [['type' => 'model', 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'created_at' => '2026-09-01T00:00:00Z']],
                'has_more' => true, 'first_id' => 'claude-opus-5-5', 'last_id' => 'claude-opus-5-5',
            ]),
            'https://api.anthropic.com/v1/models?limit=1000&after_id=claude-opus-5-5' => Http::response([
                'data' => [['type' => 'model', 'id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5', 'created_at' => '2026-10-01T00:00:00Z']],
                'has_more' => false, 'first_id' => 'claude-haiku-5-5', 'last_id' => 'claude-haiku-5-5',
            ]),
        ]);

        $this->post('/admin/configuracion/ia/modelos/anthropic')->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->header('x-api-key') === ['sk-ant-env-1234'] && $r->header('anthropic-version') === ['2023-06-01']);
        $models = AiSettings::forUi()['providers']['anthropic']['models'];
        $this->assertSame(['claude-haiku-5-5', 'claude-opus-5-5'], array_column($models, 'id'));
        $this->assertSame('Claude Haiku 5.5', $models[0]['name']);
    }

    public function test_refresh_errors(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/configuracion/ia/modelos/openai')
            ->assertSessionHas('error', 'Escriba o guarde primero la API key de OpenAI.');
        $this->post('/admin/configuracion/ia/modelos/otro')->assertNotFound();

        Http::fake(['https://api.openai.com/v1/models' => Http::response(['error' => ['message' => 'bad']], 401)]);
        $this->post('/admin/configuracion/ia/modelos/openai', ['apiKey' => 'sk-mala-clave-123'])
            ->assertSessionHas('error', 'La API key de OpenAI no es válida o no tiene permisos.');
        $this->assertSame([], AiSettings::forUi()['providers']['openai']['models']);
    }
}
