<?php

declare(strict_types=1);

namespace LaravelSqlMonitor;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LaravelSqlMonitor\Core\QueryAnalyzer;
use LaravelSqlMonitor\Core\ComplexityDetector;
use LaravelSqlMonitor\Core\OptimizationSuggester;
use LaravelSqlMonitor\Core\StackTraceCollector;
use LaravelSqlMonitor\Lifecycle\RequestQueryManager;
use LaravelSqlMonitor\Lifecycle\N1QueryDetector;
use LaravelSqlMonitor\Lifecycle\DuplicateQueryDetector;
use LaravelSqlMonitor\Monitor\SlowQueryTracker;
use LaravelSqlMonitor\Monitor\LiveQueryMonitor;
use LaravelSqlMonitor\Monitor\MetricsCollector;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;
use LaravelSqlMonitor\Storage\SqliteQueryStore;
use LaravelSqlMonitor\Console\Commands\CleanupQueryLogs;
use LaravelSqlMonitor\Console\Commands\ExportQueryLogs;

class SqlMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 合併配置
        $this->mergeConfigFrom(__DIR__ . '/Config/sql-monitor.php', 'sql-monitor');

        // ─── 核心服務（Singleton）──────────────────────────
        $this->app->singleton(QueryAnalyzer::class);
        $this->app->singleton(OptimizationSuggester::class);

        $this->app->singleton(ComplexityDetector::class, function () {
            return ComplexityDetector::fromConfig(
                config('sql-monitor.complexity', [])
            );
        });

        $this->app->singleton(StackTraceCollector::class, function () {
            return StackTraceCollector::fromConfig(
                config('sql-monitor.stack_trace', [])
            );
        });

        // ─── Lifecycle 服務 ─────────────────────────────────
        $this->app->singleton(RequestQueryManager::class, function () {
            return new RequestQueryManager(
                maxBuffer: (int) config('sql-monitor.live_monitor.max_buffer_size', 1000)
            );
        });

        $this->app->singleton(N1QueryDetector::class, function () {
            return new N1QueryDetector(
                threshold: (int) config('sql-monitor.n1_detection.threshold', 2)
            );
        });

        $this->app->singleton(DuplicateQueryDetector::class);

        // ─── Storage 服務 ──────────────────────────────────
        $this->app->singleton(QueryStoreInterface::class, function () {
            $driver = config('sql-monitor.storage.driver', 'sqlite');

            if ($driver === 'sqlite') {
                return new SqliteQueryStore(
                    config('sql-monitor.storage.database')
                );
            }

            // 未來可擴展其他 driver
            return new SqliteQueryStore();
        });

        // ─── Monitor 服務 ──────────────────────────────────
        $this->app->singleton(SlowQueryTracker::class, function ($app) {
            return new SlowQueryTracker(
                thresholdMs: (float) config('sql-monitor.slow_query.threshold_ms', 100),
                store:       $app->make(QueryStoreInterface::class),
            );
        });

        $this->app->singleton(LiveQueryMonitor::class, function () {
            return new LiveQueryMonitor(
                channel: config('sql-monitor.live_monitor.broadcast_channel', 'sql-monitor'),
            );
        });

        $this->app->singleton(MetricsCollector::class, function ($app) {
            return new MetricsCollector(
                manager:     $app->make(RequestQueryManager::class),
                n1Detector:  $app->make(N1QueryDetector::class),
                dupDetector: $app->make(DuplicateQueryDetector::class),
                slowTracker: $app->make(SlowQueryTracker::class),
            );
        });

        // ─── QueryListener ─────────────────────────────────
        $this->app->singleton(QueryListener::class);
    }

    public function boot(): void
    {
        if (! $this->shouldRun()) {
            return;
        }

        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerCommands();
        $this->registerEventListeners();
    }

    // ─── 輔助方法 ──────────────────────────────────────────

    protected function shouldRun(): bool
    {
        if (! config('sql-monitor.enabled', true)) {
            return false;
        }

        $allowedEnvs = config('sql-monitor.environments', ['local', 'testing']);

        return in_array($this->app->environment(), $allowedEnvs, true);
    }

    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            // 發布配置文件
            $this->publishes([
                __DIR__ . '/Config/sql-monitor.php' => config_path('sql-monitor.php'),
            ], 'sql-monitor-config');

            // 發布視圖
            $this->publishes([
                __DIR__ . '/Resources/views' => resource_path('views/vendor/sql-monitor'),
            ], 'sql-monitor-views');
        }
    }

    protected function registerRoutes(): void
    {
        $prefix     = config('sql-monitor.route_prefix', 'sql-monitor');
        $middleware  = config('sql-monitor.middleware', ['web']);

        // Web 路由（Dashboard）
        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(__DIR__ . '/Http/Routes/web.php');

        // API 路由
        Route::prefix("{$prefix}/api")
            ->middleware($middleware)
            ->group(__DIR__ . '/Http/Routes/api.php');
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/Resources/views', 'sql-monitor');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupQueryLogs::class,
                ExportQueryLogs::class,
            ]);
        }
    }

    protected function registerEventListeners(): void
    {
        Event::listen(
            QueryExecuted::class,
            [QueryListener::class, 'handle']
        );
    }
}
