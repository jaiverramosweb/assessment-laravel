<?php

namespace Tests\Feature\Ai;

use App\Models\AuditLog;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Analysis\AnthropicAnalyzer;
use App\Services\Ai\Analysis\OpenAiAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AI provider settings: database values (keys encrypted) over the .env fallback. */
class AiSettingsTest extends TestCase
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

    private ?string $adminId = null;

    private function admin(): string
    {
        return $this->adminId ??= $this->createAdmin()->id;
    }

    public function test_without_saved_settings_it_uses_the_env_values(): void
    {
        $this->assertFalse(AiSettings::configured());
        $this->assertNull(AiSettings::analyzer());

        config(['assessments.anthropic.api_key' => 'sk-ant-env']);

        $this->assertSame('anthropic', AiSettings::provider());
        $this->assertSame('claude-opus-5-5', AiSettings::model());
        $this->assertTrue(AiSettings::configured());
        $this->assertInstanceOf(AnthropicAnalyzer::class, AiSettings::analyzer());
        $this->assertSame('env', AiSettings::forUi()['providers']['anthropic']['keySource']);
    }

    public function test_saved_key_is_encrypted_and_used_by_the_openai_analyzer(): void
    {
        $result = AiSettings::save(['provider' => 'openai', 'openaiModel' => 'gpt-prueba', 'openaiApiKey' => ' '.self::OPENAI_KEY.' '], $this->admin());

        $this->assertTrue($result['ok']);
        $stored = DB::table('app_settings')->where('key', 'ai.openai.api_key')->value('value');
        $this->assertStringStartsWith('v1:', $stored);
        $this->assertStringNotContainsString(self::OPENAI_KEY, $stored);
        $this->assertSame(self::OPENAI_KEY, AiSettings::apiKey('openai'));

        $analyzer = AiSettings::analyzer();
        $this->assertInstanceOf(OpenAiAnalyzer::class, $analyzer);
        $this->assertSame('gpt-prueba', $analyzer->model());
        $this->assertSame('gpt-prueba', AiSettings::model());
    }

    public function test_ui_data_never_contains_the_key(): void
    {
        AiSettings::save(['provider' => 'openai', 'openaiModel' => 'gpt-prueba', 'openaiApiKey' => self::OPENAI_KEY], $this->admin());

        $ui = AiSettings::forUi();

        $this->assertStringNotContainsString(self::OPENAI_KEY, json_encode($ui));
        $this->assertSame(['configured' => true, 'keySource' => 'db', 'keyHint' => '9876', 'model' => 'gpt-prueba', 'models' => [], 'modelsFetchedAt' => null],
            $ui['providers']['openai']);
        $this->assertSame('openai', $ui['provider']);
    }

    public function test_database_values_win_over_env_and_an_empty_key_field_keeps_the_saved_key(): void
    {
        config(['assessments.anthropic.api_key' => 'sk-ant-env']);
        AiSettings::save(['provider' => 'anthropic', 'anthropicModel' => 'claude-sonnet-5-5', 'anthropicApiKey' => 'sk-ant-guardada-1111'], $this->admin());
        AiSettings::save(['provider' => 'anthropic', 'anthropicModel' => 'claude-haiku-5-5', 'anthropicApiKey' => ''], $this->admin());

        $this->assertSame('sk-ant-guardada-1111', AiSettings::apiKey('anthropic'));
        $this->assertSame('claude-haiku-5-5', AiSettings::model());
    }

    public function test_remove_key_falls_back_to_env(): void
    {
        AiSettings::save(['provider' => 'anthropic', 'anthropicModel' => 'claude-opus-5-5', 'anthropicApiKey' => 'sk-ant-guardada-1111'], $this->admin());
        config(['assessments.anthropic.api_key' => 'sk-ant-env']);

        AiSettings::save(['provider' => 'anthropic', 'anthropicModel' => 'claude-opus-5-5', 'removeAnthropicKey' => true], $this->admin());

        $this->assertSame('sk-ant-env', AiSettings::apiKey('anthropic'));
        $this->assertFalse(DB::table('app_settings')->where('key', 'ai.anthropic.api_key')->exists());
    }

    public function test_validation_errors(): void
    {
        $this->assertSame(['provider' => 'Elija un proveedor de IA.'], AiSettings::save(['provider' => 'otro'], $this->admin())['errors']);
        $this->assertSame(['openaiModel' => 'El nombre del modelo no es válido.'],
            AiSettings::save(['provider' => 'anthropic', 'openaiModel' => 'gpt prueba; drop'], $this->admin())['errors']);
        $this->assertSame(['openaiApiKey' => 'La API key no es válida.'],
            AiSettings::save(['provider' => 'anthropic', 'openaiApiKey' => 'corta'], $this->admin())['errors']);
        $this->assertSame(['openaiApiKey' => 'Para usar OpenAI guarde su API key.', 'openaiModel' => 'Para usar OpenAI elija un modelo.'],
            AiSettings::save(['provider' => 'openai'], $this->admin())['errors']);

        $this->assertSame(0, DB::table('app_settings')->count());
    }

    public function test_a_key_that_cannot_be_decrypted_counts_as_missing(): void
    {
        DB::table('app_settings')->insert(['key' => 'ai.openai.api_key', 'value' => 'v1:roto:roto:roto', 'updated_at' => 1]);

        $this->assertNull(AiSettings::apiKey('openai'));
    }

    public function test_changes_are_audited_without_the_key(): void
    {
        AiSettings::save(['provider' => 'openai', 'openaiModel' => 'gpt-prueba', 'openaiApiKey' => self::OPENAI_KEY], $this->admin(), '10.0.0.1');

        $log = AuditLog::where('action', 'ia.configuracion_cambiada')->sole();
        $this->assertSame('openai', $log->metadata['proveedor']);
        $this->assertSame('guardada', $log->metadata['claves']['openai']);
        $this->assertStringNotContainsString(self::OPENAI_KEY, json_encode($log->metadata));
    }
}
