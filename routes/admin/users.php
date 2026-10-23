<?php

// Admin users (mounted under /admin with the admin middleware by routes/web.php).

use App\Http\Controllers\Admin\Users\AccountController;
use App\Http\Controllers\Admin\Users\UserController;
use Illuminate\Support\Facades\Route;

// Admin users ("Usuarios" tab) and the logged-in admin's own account ("Mi cuenta").
Route::get('/usuarios', [UserController::class, 'index']);
Route::post('/usuarios', [UserController::class, 'store']);
Route::post('/usuarios/{userId}/password', [UserController::class, 'password']);
Route::post('/usuarios/{userId}/desactivar', [UserController::class, 'deactivate']);
Route::post('/usuarios/{userId}/activar', [UserController::class, 'activate']);
Route::post('/usuarios/{userId}/acceso', [UserController::class, 'access']);

// Every admin, limited ones included, can change their own password.
Route::withoutMiddleware('admin.full')->group(function () {
    Route::get('/cuenta', [AccountController::class, 'show']);
    Route::post('/cuenta/password', [AccountController::class, 'password']);
});
