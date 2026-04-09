<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelSqlMonitor\Monitor\MetricsCollector;

class DashboardController extends Controller
{
    public function __construct(
        protected MetricsCollector $metrics,
    ) {}

    /**
     * 顯示 Dashboard 主頁面。
     */
    public function index()
    {
        return view('sql-monitor::dashboard', [
            'metrics' => $this->metrics->collect(),
        ]);
    }
}
