<?php

// Mounted under /admin with the `admin` middleware (see routes/web.php).

use App\Http\Controllers\Admin\Ai\AiAnalysisController;
use Illuminate\Support\Facades\Route;

// Body: `questionId` (omitted = global analysis), `regenerar` (boolean).
Route::post('/assessments/{id}/ia/analizar', [AiAnalysisController::class, 'analyze']);
