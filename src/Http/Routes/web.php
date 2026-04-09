<?php

use Illuminate\Support\Facades\Route;
use LaravelSqlMonitor\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'index'])->name('sql-monitor.dashboard');
