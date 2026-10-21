<?php

use App\Http\Controllers\Admin\SessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'));

Route::get('/admin/login', [SessionController::class, 'create']);
Route::post('/admin/login', [SessionController::class, 'store']);
Route::post('/admin/logout', [SessionController::class, 'destroy']);

// Admin panel: every file in routes/admin/ is mounted under /admin and requires a session.
Route::middleware('admin')->prefix('admin')->group(function () {
    foreach (glob(__DIR__.'/admin/*.php') as $file) {
        require $file;
    }
});

// Public routes (/a/{slug}...): every file in routes/public/.
foreach (glob(__DIR__.'/public/*.php') as $file) {
    require $file;
}
