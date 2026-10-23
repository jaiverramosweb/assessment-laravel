<?php

// "Configuración" (mounted under /admin with the admin middleware by routes/web.php; full access only).

use App\Http\Controllers\Admin\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/configuracion', [SettingsController::class, 'show']);
Route::post('/configuracion/ia', [SettingsController::class, 'saveAi']);
Route::post('/configuracion/ia/modelos/{provider}', [SettingsController::class, 'refreshModels'])
    ->whereIn('provider', ['anthropic', 'openai']);
