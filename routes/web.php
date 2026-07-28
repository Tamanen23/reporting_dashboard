<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReportGenerationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('reports.create') : redirect()->route('login');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/reports', [ReportGenerationController::class, 'index'])->name('reports.index');
    Route::get('/reports/create', [ReportGenerationController::class, 'create'])->name('reports.create');
    Route::post('/reports', [ReportGenerationController::class, 'store'])->name('reports.store');
    Route::get('/reports/{report}', [ReportGenerationController::class, 'show'])->name('reports.show');
    Route::post('/reports/{report}/retry', [ReportGenerationController::class, 'retry'])->name('reports.retry');
    Route::get('/reports/{report}/outputs/{output}', [ReportGenerationController::class, 'download'])->name('reports.download');
});
