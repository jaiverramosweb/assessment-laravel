<?php

// Mounted under /admin with the `admin` middleware (see routes/web.php).

use App\Http\Controllers\Admin\Participants\ParticipantController;
use Illuminate\Support\Facades\Route;

Route::get('/assessments/{id}/participantes', [ParticipantController::class, 'index']);
Route::post('/assessments/{id}/participantes', [ParticipantController::class, 'store']);
Route::post('/assessments/{id}/participantes/importar', [ParticipantController::class, 'import']);
Route::get('/assessments/{id}/participantes/plantilla', [ParticipantController::class, 'template']);
Route::delete('/assessments/{id}/participantes/{participantId}', [ParticipantController::class, 'destroy']);
