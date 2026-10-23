<?php

// Mounted under /admin with the `admin` middleware (see routes/web.php).

use App\Http\Controllers\Admin\Reports\ClientReportController;
use App\Http\Controllers\Admin\Reports\MeasurementController;
use App\Http\Controllers\Admin\Reports\ReportController;
use Illuminate\Support\Facades\Route;

// Limited admins with the "reports" permission can open these, only for the clients they were granted.
Route::withoutMiddleware('admin.full')->middleware('admin.reports:assessment')->group(function () {
    Route::get('/assessments/{id}/reportes', [ReportController::class, 'index']);
    Route::get('/assessments/{id}/reportes/excel', [ReportController::class, 'excel']);
    Route::get('/assessments/{id}/reportes/pdf', [ReportController::class, 'pdf']);

    Route::get('/assessments/{id}/mediciones', [MeasurementController::class, 'index']);
    Route::get('/assessments/{id}/mediciones/excel', [MeasurementController::class, 'excel']);
    Route::get('/assessments/{id}/mediciones/pdf', [MeasurementController::class, 'pdf']);
});

Route::withoutMiddleware('admin.full')->middleware('admin.reports:client')->group(function () {
    Route::get('/clientes/{id}/informe', [ClientReportController::class, 'index']);
    Route::get('/clientes/{id}/informe/excel', [ClientReportController::class, 'excel']);
    Route::get('/clientes/{id}/informe/pdf', [ClientReportController::class, 'pdf']);
});
