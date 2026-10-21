<?php

// Mounted under /admin with the `admin` middleware (see routes/web.php).

use App\Http\Controllers\Admin\Invitations\InvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/assessments/{id}/invitaciones', [InvitationController::class, 'index']);
Route::post('/assessments/{id}/invitaciones/plantilla', [InvitationController::class, 'saveTemplate']);
Route::post('/assessments/{id}/invitaciones/enviar', [InvitationController::class, 'send']);
Route::post('/assessments/{id}/invitaciones/copiar', [InvitationController::class, 'copy']);
