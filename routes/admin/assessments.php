<?php

// Assessments: dashboard, creation, General tab and questions editor (mounted under /admin).
// The original SvelteKit named actions (?/foo) are POST routes with the same Spanish names.

use App\Http\Controllers\Admin\Assessments\AssessmentController;
use App\Http\Controllers\Admin\Assessments\GeneralController;
use App\Http\Controllers\Admin\Assessments\QuestionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AssessmentController::class, 'index']);
Route::get('/assessments/nuevo', [AssessmentController::class, 'create']);
Route::post('/assessments/nuevo', [AssessmentController::class, 'store']);

Route::prefix('/assessments/{id}')->group(function () {
    Route::get('/', [GeneralController::class, 'show']);
    Route::post('/guardar', [GeneralController::class, 'update']);
    Route::post('/password', [GeneralController::class, 'password']);
    Route::post('/ver-password', [GeneralController::class, 'revealPassword']);
    Route::post('/publicar', [GeneralController::class, 'publish']);
    Route::post('/borrador', [GeneralController::class, 'draft']);
    Route::post('/archivar', [GeneralController::class, 'archive']);
    Route::post('/eliminar', [GeneralController::class, 'destroy']);
    Route::post('/medicion', [GeneralController::class, 'nextMeasurement']);
    Route::post('/duplicar', [GeneralController::class, 'duplicate']);

    Route::get('/preguntas', [QuestionController::class, 'index']);
    Route::post('/preguntas/guardar', [QuestionController::class, 'save']);
    Route::post('/preguntas/importar', [QuestionController::class, 'import']);
    Route::post('/preguntas/leer-word', [QuestionController::class, 'readWord']);
    Route::get('/preguntas/plantilla', [QuestionController::class, 'excelTemplate']);
    Route::get('/preguntas/plantilla-word', [QuestionController::class, 'wordTemplate']);
});
