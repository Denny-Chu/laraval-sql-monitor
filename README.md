# Laravel SQL Monitor — 完整的 SQL 查詢監控和優化工具

[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green)](#license)

一個全功能的 Laravel 擴展，提供**動態分析**和**靜態分析**能力，幫助開發者在開發時期就發現和優化 SQL 查詢問題。

## 功能概覽

### 🔍 動態分析（執行後）

- ✅ **實時查詢監控** - 捕獲每條 SQL 及其執行時間
- ✅ **複雜度評分** - 自動計算查詢複雜度（0-100）
- ✅ **N+1 檢測** - 識別 N+1 查詢模式
- ✅ **重複查詢檢測** - 找出完全相同的重複查詢
- ✅ **Slow Query 追蹤** - 記錄超過閾值的查詢
- ✅ **棧信息收集** - IDE 可點擊的調用棧

### 🔬 靜態分析（執行前）✨ 新增

- ✅ **Query Builder 分析** - 在執行前檢查查詢結構
- ✅ **索引檢查** - 驗證 WHERE 和 JOIN 欄位是否有索引
- ✅ **成本估算** - 預測查詢的執行成本
- ✅ **選擇率預測** - 估計查詢會返回多少資料
- ✅ **優化建議** - 自動生成可操作的改進方案

### 📊 Web Dashboard

- 📈 查詢摘要和統計
- 🚨 N+1 警告面板
- 🔄 重複查詢面板
- 📋 完整查詢日誌
- 🎨 Tailwind CSS 設計

### 🔌 API 和集成

- 📡 REST API - 以 JSON 格式取得所有數據
- 🌐 WebSocket 廣播 - 實時推送查詢事件
- ⚙️ 可配置和可擴展 - 支持自定義 Driver 和規則

## 快速開始

### 安裝

```bash
composer require denny-chu/laravel-sql-monitor

# 發布配置和視圖
php artisan vendor:publish --provider="LaravelSqlMonitor\SqlMonitorServiceProvider"
```

### 配置

在 `.env` 中啟用：

```env
SQL_MONITOR_ENABLED=true
SQL_MONITOR_IDE=vscode  # vscode | phpstorm | sublime
```

### 使用示例

#### 動態分析（自動）

```php
// 應用代碼 - 自動監控
$users = User::with('posts')
    ->where('active', true)
    ->get();

// QueryListener 自動捕獲、分析、記錄
// 結果可在 http://localhost:8000/sql-monitor 查看
```

#### 靜態分析（手動）

```php
use LaravelSqlMonitor\StaticAnalysis\QueryBuilderAnalyzer;
use LaravelSqlMonitor\StaticAnalysis\IndexInspector;
use LaravelSqlMonitor\StaticAnalysis\StructureAnalyzer;

$query = User::query()
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->where('users.active', true)
    ->where('posts.published', true);

// 方式 1：使用 Macro（簡潔）
$analysis = $query->analyzeStatic()->analyze();
echo $query->explainStructure();

// 方式 2：完整分析
$analyzer  = new QueryBuilderAnalyzer($query);
$analysis  = $analyzer->analyze();
$inspector = new IndexInspector();
$structure = new StructureAnalyzer($analysis, $inspector);

$score = $structure->calculateComplexityScore();      // 0-100
$suggestions = $structure->getOptimizationSuggestions();

foreach ($suggestions as $suggestion) {
    echo "[{$suggestion['severity']}] {$suggestion['message']}\n";
}
```

## 核心模組

### 📦 模組結構

```
src/
├── Core/                    # SQL 分析核心
│   ├── QueryAnalyzer.php           # 解析 SQL 結構
│   ├── ComplexityDetector.php      # 計算複雜度
│   └── OptimizationSuggester.php   # 生成建議
│
├── StaticAnalysis/          # 靜態分析（新）
│   ├── QueryBuilderAnalyzer.php    # 分析 Query Builder
│   ├── IndexInspector.php          # 檢查索引
│   └── StructureAnalyzer.php       # 結構分析
│
├── Lifecycle/               # 請求級分析
│   ├── N1QueryDetector.php         # N+1 檢測
│   └── DuplicateQueryDetector.php  # 重複查詢檢測
│
├── Monitor/                 # 監控和追蹤
│   ├── SlowQueryTracker.php        # 慢查詢追蹤
│   └── LiveQueryMonitor.php        # 實時廣播
│
└── Http/                    # Web 層
    ├── Controllers/                # Dashboard 和 API
    └── Routes/                     # 路由定義
```

## 配置示例

### `config/sql-monitor.php`

```php
[
    'enabled'                => env('SQL_MONITOR_ENABLED', true),
    'environments'           => ['local', 'testing'],

    'complexity' => [
        'join_threshold'       => 5,      // JOIN 數超過 5 時警告
        'subquery_depth_limit' => 3,      // 子查詢深度限制
        'detect_select_star'   => true,   // 檢測 SELECT *
    ],

    'n1_detection' => [
        'threshold' => 2,                 // 同一查詢執行 2+ 次視為 N+1
    ],

    'slow_query' => [
        'threshold_ms' => 100,            // 100ms 以上視為慢查詢
    ],

    'live_monitor' => [
        'enabled' => true,
        'broadcast_channel' => 'sql-monitor',
    ],
]
```

## API 文檔

### 前端訪問

```
GET  /sql-monitor/                  → Dashboard
GET  /sql-monitor/api/queries       → 查詢列表
GET  /sql-monitor/api/analytics     → 分析數據
GET  /sql-monitor/api/slow-queries  → 慢查詢
GET  /sql-monitor/api/stats         → 統計信息
DELETE /sql-monitor/api/logs        → 清理日誌
```

### 後端使用

```php
use LaravelSqlMonitor\Lifecycle\RequestQueryManager;
use LaravelSqlMonitor\Monitor\MetricsCollector;

// 取得當前請求的查詢
$manager = app(RequestQueryManager::class);
$count = $manager->count();
$stats = $manager->getStats();

// 取得完整指標
$collector = app(MetricsCollector::class);
$metrics = $collector->collect();
```

## 自動化檢查

### 在中間件中使用

```php
// app/Http/Middleware/SqlOptimizationCheck.php
class SqlOptimizationCheck
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (app()->isLocal()) {
            $analysis = app(RequestQueryManager::class);

            if ($analysis->getStats()['slow_query_count'] > 3) {
                Log::warning('Too many slow queries', [
                    'count' => $analysis->getStats()['slow_query_count'],
                    'url'   => $request->url(),
                ]);
            }
        }

        return $response;
    }
}
```

### 在測試中驗證

```php
public function test_query_performance()
{
    $query = User::with('posts')
        ->where('active', true);

    $analysis = $query->analyzeStatic()->analyze();

    $this->assertFalse($analysis->hasCriticalIssues());

    $structure = new StructureAnalyzer($analysis);
    $this->assertLessThan(50, $structure->calculateComplexityScore());
}
```

## 性能和開銷

| 操作 | 開銷 |
|------|------|
| 動態分析（per query） | 2-5ms |
| 靜態分析（per query builder） | < 1ms |
| 索引檢查 | 10-50ms（有快取） |
| 持久化（SQLite） | 5-20ms（異步） |

**說明**：所有分析都只在開發環境運行，不影響生產環境性能。

## 支持的資料庫

### 動態分析
- ✅ MySQL / MariaDB
- ✅ PostgreSQL
- ✅ SQLite
- ✅ SQL Server（基本）

### 靜態分析 - 索引檢查
- ✅ MySQL / MariaDB（完整支持）
- ✅ SQLite（基本支持）
- 🚧 PostgreSQL（待擴展）
- 🚧 SQL Server（待擴展）

## 常見用例

### 用例 1：在 CI/CD 中檢查查詢結構

```php
// 在 GitHub Actions 中運行
public function test_all_queries_are_optimized()
{
    $queries = [
        User::with('posts'),
        Post::with('comments.author'),
        // ...
    ];

    foreach ($queries as $query) {
        $analysis = $query->analyzeStatic()->analyze();
        $this->assertFalse($analysis->hasCriticalIssues());
    }
}
```

### 用例 2：開發時實時警告

```php
// 在本地開發時自動警告
if (app()->isLocal()) {
    $analysis = $query->analyzeStatic()->analyze();

    if ($analysis->hasCriticalIssues()) {
        throw new \RuntimeException('Query optimization failed');
    }
}
```

### 用例 3：生產環境監控

```php
// Laravel Pulse 替代方案
// 或與 Laravel Pulse 配合使用
// 通過 Response Header 包含統計
// X-Sql-Monitor-Query-Count: 15
// X-Sql-Monitor-Total-Time: 245.5ms
```

## 文檔

- 📖 [完整架構概覽](./docs/ARCHITECTURE_OVERVIEW.md)
- 🔍 [動態分析指南](./docs/DYNAMIC_ANALYSIS.md)
- 🔬 [靜態分析指南](./docs/STATIC_ANALYSIS.md)
- 📚 [API 文檔](./docs/API.md)
- 🛠️ [開發者指南](./docs/DEVELOPER_GUIDE.md)

## 路線圖

- ✅ Phase 1: 核心動態分析和 Dashboard
- ✅ Phase 2: 靜態分析和索引檢查
- 🚧 Phase 3: AI 驅動的建議
- 💭 Phase 4: 自動優化執行

## 貢獻

歡迎提交 PR 和 Issue！請遵循我們的[貢獻指南](./CONTRIBUTING.md)。

## License

MIT License © 2026. See [LICENSE](./LICENSE) file for details.

## 致謝

感謝 [phpmyadmin/sql-parser](https://github.com/phpmyadmin/sql-parser) 提供強大的 SQL 解析能力。

---

**開始使用**：訪問 `http://localhost:8000/sql-monitor` 查看 Dashboard！

有問題或建議？[提交 Issue](https://github.com/your-org/laravel-sql-monitor/issues) 或 [討論區](https://github.com/your-org/laravel-sql-monitor/discussions)。
