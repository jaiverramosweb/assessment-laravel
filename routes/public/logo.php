<?php

// Public logo of an assessment's client (never for drafts).

use App\Http\Controllers\Public\LogoController;
use Illuminate\Support\Facades\Route;

Route::get('/a/{slug}/logo', [LogoController::class, 'show']);
