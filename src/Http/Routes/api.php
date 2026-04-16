<?php

use Illuminate\Support\Facades\Route;
use LaravelSqlMonitor\Http\Controllers\ApiController;

Route::get('/queries',      [ApiController::class, 'queries'])->name('sql-monitor.api.queries');
Route::get('/analytics',    [ApiController::class, 'analytics'])->name('sql-monitor.api.analytics');
Route::get('/slow-queries', [ApiController::class, 'slowQueries'])->name('sql-monitor.api.slow-queries');
Route::get('/stats',        [ApiController::class, 'stats'])->name('sql-monitor.api.stats');
Route::get('/poll',         [ApiController::class, 'poll'])->name('sql-monitor.api.poll');
Route::delete('/logs',      [ApiController::class, 'cleanup'])->name('sql-monitor.api.cleanup');
