<?php

// Clients (mounted under /admin with the admin middleware by routes/web.php).

use App\Http\Controllers\Admin\Clients\ClientBrandController;
use App\Http\Controllers\Admin\Clients\ClientController;
use Illuminate\Support\Facades\Route;

Route::get('/clientes', [ClientController::class, 'index']);
Route::post('/clientes', [ClientController::class, 'store']);
Route::get('/clientes/{id}', [ClientController::class, 'show']);
Route::post('/clientes/{id}/nombre', [ClientController::class, 'rename']);
Route::delete('/clientes/{id}', [ClientController::class, 'destroy']);

// The logo shows on report screens too: limited admins can load it for the clients in their scope.
Route::get('/clientes/{id}/logo', [ClientBrandController::class, 'showLogo'])
    ->withoutMiddleware('admin.full')
    ->middleware('admin.reports:client');
Route::post('/clientes/{id}/logo', [ClientBrandController::class, 'uploadLogo']);
Route::delete('/clientes/{id}/logo', [ClientBrandController::class, 'removeLogo']);
Route::post('/clientes/{id}/colores', [ClientBrandController::class, 'saveColors']);
Route::delete('/clientes/{id}/colores', [ClientBrandController::class, 'resetColors']);
