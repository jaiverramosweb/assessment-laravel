<?php

use App\Http\Controllers\Participant\AccessController;
use App\Http\Controllers\Participant\AnswerController;
use App\Http\Controllers\Participant\DeliveredController;
use App\Http\Middleware\LoadParticipantSession;
use Illuminate\Support\Facades\Route;

// Participant flow. The slug format is checked by the services (unknown or malformed = 404
// with the original message), so the routes take any segment.
Route::get('/a/{slug}', [AccessController::class, 'show']);
Route::post('/a/{slug}', [AccessController::class, 'store']);

Route::middleware(LoadParticipantSession::class)->group(function () {
    Route::get('/a/{slug}/responder', [AnswerController::class, 'show']);
    Route::post('/a/{slug}/responder/guardar', [AnswerController::class, 'save']);
    Route::post('/a/{slug}/responder/entregar', [AnswerController::class, 'submit']);
});

Route::get('/a/{slug}/entregado', [DeliveredController::class, 'show']);
