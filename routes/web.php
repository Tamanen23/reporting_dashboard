<?php

use App\Http\Controllers\Admin\UserInvitationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ReportGenerationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('reports.create') : redirect()->route('login');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:3,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])
        ->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [InvitationController::class, 'update'])
    ->middleware('throttle:10,1')
    ->name('invitations.update');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/reports', [ReportGenerationController::class, 'index'])->name('reports.index');
    Route::get('/pipeline', [ReportGenerationController::class, 'pipeline'])->name('reports.pipeline');
    Route::get('/reports/create', [ReportGenerationController::class, 'create'])->name('reports.create');
    Route::get('/reports/trash', [ReportGenerationController::class, 'trash'])->name('reports.trash');
    Route::post('/reports/trash/{report}/restore', [ReportGenerationController::class, 'restore'])->name('reports.restore');
    Route::post('/reports', [ReportGenerationController::class, 'store'])->name('reports.store');
    Route::get('/reports/{report}', [ReportGenerationController::class, 'show'])->name('reports.show');
    Route::delete('/reports/{report}', [ReportGenerationController::class, 'destroy'])->name('reports.destroy');
    Route::post('/reports/{report}/retry', [ReportGenerationController::class, 'retry'])->name('reports.retry');
    Route::get('/reports/{report}/outputs/{output}', [ReportGenerationController::class, 'download'])->name('reports.download');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->group(function (): void {
    Route::get('/users', [UserInvitationController::class, 'index'])->name('admin.users.index');
    Route::post('/users', [UserInvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('admin.users.store');
});
