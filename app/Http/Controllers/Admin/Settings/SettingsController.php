<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiModels;
use App\Services\Ai\AiSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** "Configuración": AI provider, model of each provider, API keys (stored encrypted) and model lists. */
class SettingsController extends Controller
{
    private const AI_FIELDS = [
        'provider', 'anthropicModel', 'openaiModel', 'anthropicApiKey', 'openaiApiKey', 'removeAnthropicKey', 'removeOpenaiKey',
    ];

    public function show()
    {
        return Inertia::render('Admin/Settings/Index', [
            'ai' => AiSettings::forUi(),
            'labels' => AiSettings::PROVIDERS,
            'suggestions' => ['anthropic' => AiModels::ANTHROPIC_SUGGESTIONS, 'openai' => []],
        ]);
    }

    public function saveAi(Request $request)
    {
        $result = AiSettings::save($request->only(self::AI_FIELDS), $request->attributes->get('admin')->id, $request->ip());
        if (! $result['ok']) {
            return back(303)->withErrors($result['errors']);
        }
        $provider = AiSettings::provider();

        return back(303)->with('success', 'Configuración de IA guardada. Los próximos análisis usarán '
            .AiSettings::label($provider).' ('.AiSettings::model($provider).').');
    }

    /** Reads the provider's current models with the key typed in the form (not saved) or the saved one. */
    public function refreshModels(Request $request, string $provider)
    {
        $typed = $request->input('apiKey');
        $key = is_string($typed) && trim($typed) !== '' ? trim($typed) : AiSettings::apiKey($provider);
        $label = AiSettings::label($provider);
        if ($key === null) {
            return back(303)->with('error', "Escriba o guarde primero la API key de {$label}.");
        }

        $result = AiModels::fetch($provider, $key);
        if (! $result['ok']) {
            return back(303)->with('error', $result['error']);
        }
        AiSettings::rememberModels($provider, $result['models']);
        $count = count($result['models']);

        return back(303)->with('success', "Lista de modelos de {$label} actualizada: {$count} ".($count === 1 ? 'modelo.' : 'modelos.'));
    }
}
