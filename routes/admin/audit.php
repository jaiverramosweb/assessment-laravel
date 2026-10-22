<?php

// Mounted under /admin with the `admin` middleware (see routes/web.php).

use App\Http\Controllers\Admin\Audit\AuditController;
use Illuminate\Support\Facades\Route;

Route::get('/auditoria', [AuditController::class, 'index']);
