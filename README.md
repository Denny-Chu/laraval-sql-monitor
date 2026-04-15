# Laravel SQL Monitor

[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green)](#license)

一個全功能的 Laravel 擴展，提供**動態分析**（執行時監控）和**靜態分析**（AST 掃描）能力，幫助開發者在開發時期就發現和優化 SQL 查詢問題。

---

## 功能概覽

### 動態分析（執行後）

| 功能 | 說明 |
|------|------|
| 實時查詢監控 | 捕獲每條 SQL 及執行時間 |
| 複雜度評分 | 自動計算查詢複雜度（0–100） |
| N+1 檢測 | 識別 N+1 查詢模式 |
| 重複查詢檢測 | 找出完全相同的重複查詢 |
| Slow Query 追蹤 | 記錄超過閾值的查詢並持久化 |
| Call Stack 收集 | IDE 可點擊的調用棧 |

### 靜態分析（執行前）

透過 AST 解析 PHP 原始碼，**不需要執行查詢**即可偵測問題：

| 偵測項目 | issue code | 嚴重度 |
|----------|-----------|--------|
| SELECT * | `select-star` | info |
| 無 WHERE 全表掃描 | `no-where` | warning |
| 無 LIMIT 無界結果集 | `no-limit` | warning |
| WHERE 欄位缺少索引 | `missing-index` | warning |
| 過量 JOIN（>5） | `excessive-joins` | critical |
| 偏多 JOIN（>3） | `many-joins` | warning |
| JOIN 索引提示 | `join-index-hint` | info |
| GROUP BY 索引提示 | `group-by-hint` | info |
| LIMIT 無 ORDER BY | `limit-without-order` | info |
| UNION 使用提示 | `union-detected` | info |
| Eloquent N+1 風險 | `n1-risk` | info |
| Raw SQL 偵測 | `raw-sql` | info |
| **複合索引建議** | — | — |

---

## 安裝

```bash
composer require denny/laravel-sql-monitor
```

發布設定檔：

```bash
php artisan vendor:publish --tag=sql-monitor-config
```

啟用（`.env`）：

```env
APP_ENV=local
SQL_MONITOR_ENABLED=true
```

> 套件只在 `APP_ENV=local` 或 `testing` 時啟動，生產環境不會載入。

---

## 靜態分析

### 基本用法

```bash
# 掃描整個 app/ 目錄
php artisan sql-monitor:analyse

# 指定目錄
php artisan sql-monitor:analyse --path=app/Repositories

# 指定單一檔案
php artisan sql-monitor:analyse --path=app/Models/User.php

# 指定類別（完整命名空間或短名稱）
php artisan sql-monitor:analyse --class=UserRepository
php artisan sql-monitor:analyse --class="App\Repositories\UserRepository"
```

### 完整 CLI 選項

| 選項 | 預設 | 說明 |
|------|------|------|
| `--path=` | — | 分析指定目錄或檔案 |
| `--class=` | — | 分析指定類別 |
| `--format=` | `table` | 輸出格式：`table` / `json` / `summary` |
| `--min-severity=` | `info` | 最低顯示嚴重度：`info` / `warning` / `critical` |
| `--sort=` | `file` | 排序：`file` / `cost` / `complexity` / `severity` |
| `--no-index-check` | — | 跳過資料庫索引檢查（不需 DB 連線） |
| `--no-index-recommend` | — | 跳過複合索引建議計算 |

### 輸出範例

```
=== app/Repositories/OrderRepository.php

  ▲ OrderRepository::findByUser()  行 24
    eloquent::where('user_id', '$userId')->where('status', '$status')->get
    表：order  | 複雜度：25/100 [OK]  | 成本：0.49 [LOW]
    ℹ  [select-star] 使用了 SELECT *
    ▲  [no-limit] 無 LIMIT 限制，可能回傳大量資料
    ▲  [missing-index] WHERE 欄位 `status` 在 `order` 表中無索引

=== 複合索引建議

  order（2 個建議，共 15 次查詢覆蓋）

    建議 1: (user_id, status)  使用次數：12
      → 等值欄位依選擇率排序：user_id(選擇率 0.920) → status(選擇率 0.005)
      ⚠  可 DROP 現有索引：idx_user（已被新索引 left-prefix 覆蓋）

    建議 2: (user_id, created_at)  使用次數：3
      → 等值欄位依選擇率排序：user_id(選擇率 0.920)
      → ORDER BY 欄位放尾端：created_at desc（可避免 filesort）
```

### JSON 輸出（適合 CI / diff）

```bash
php artisan sql-monitor:analyse --format=json | jq '.summary'
```

JSON 結構：

```json
{
  "generated_at": "2026-04-15T10:00:00+00:00",
  "total_sites": 1114,
  "summary": {
    "critical": 0, "warning": 397, "info": 335, "ok": 382,
    "avg_complexity": 11.0, "avg_cost": 1.21
  },
  "reports": [...],
  "index_recommendations": {
    "order": [
      {
        "columns": ["user_id", "status"],
        "frequency": 12,
        "selectivities": {"user_id": 0.92, "status": 0.005},
        "replaces": ["idx_user"],
        "patterns": [...]
      }
    ]
  }
}
```

### 複合索引建議演算法

分析所有 call site 的 WHERE / ORDER BY 模式，依以下規則建構最優複合索引：

1. **Equality first** — 等值條件（`=`, `IN`, `IS NULL`）放在索引左側
2. **High selectivity first** — 等值欄位之間按選擇率（`COUNT(DISTINCT) / COUNT(*)`）由高到低排序
3. **Range last** — 範圍條件（`>`, `<`, `BETWEEN`）放在等值欄位之後（MySQL 限制：每個索引只能使用一個 range 欄位）
4. **ORDER BY tail** — 若查詢排序欄位未被覆蓋且方向一致，放在索引尾端以避免 filesort
5. **Existing index deduplication** — 若現有索引已能透過 leftmost prefix 覆蓋此 pattern，不重複建議
6. **Supersede detection** — 若新索引的 left-prefix 完全涵蓋某個現有索引，標記可 DROP

不納入建議的條件：`LIKE '%xxx%'`（前導萬用字元）、`!=`、`NOT IN`、`whereDate/Year/Month`（非 sargable）。

---

## 設定檔

`config/sql-monitor.php` 完整說明：

```php
return [
    // 啟用開關
    'enabled'      => env('SQL_MONITOR_ENABLED', true),
    'environments' => ['local', 'testing'],

    // 監控連線白/黑名單
    'connections'          => [],   // 空 = 監控所有連線
    'excluded_connections' => [],   // 永遠不監控（storage / IndexInspector 專用連線）

    // 慢查詢持久化 storage
    'storage' => [
        'driver'          => env('SQL_MONITOR_STORAGE_DRIVER', 'sqlite'),
        // sqlite（推薦）：使用獨立 SQLite 檔案，零迴圈風險
        // database：寫入指定 MySQL/PostgreSQL 連線（建議用獨立連線）
        'database'        => env('SQL_MONITOR_STORAGE_DATABASE') ?: null,
        'connection'      => env('SQL_MONITOR_STORAGE_CONNECTION') ?: null,
        'table'           => 'sql_monitor_logs',
        'retention_hours' => 24,
    ],

    // 靜態分析 log 輸出（tee 模式）
    'static_analysis' => [
        'output_path'    => env('SQL_MONITOR_ANALYSE_OUTPUT_PATH', storage_path('logs/sql-monitor')),
        // null / 空字串 = 不寫檔
        'analyse_log'    => env('SQL_MONITOR_ANALYSE_LOG',    'analyse-{date}.log'),
        'suggestion_log' => env('SQL_MONITOR_SUGGESTION_LOG', 'suggestion-{date}.log'),
        // {date} 佔位符會替換為當天日期（YYYY-MM-DD），每日自動旋轉
        // 同一天多次執行：append 模式，不覆蓋
        'log_format'     => env('SQL_MONITOR_ANALYSE_LOG_FORMAT', 'text'),
        // text = plain text（人讀）| json = JSON 格式
    ],

    // SQL 複雜度分析
    'complexity' => [
        'enabled'              => true,
        'join_threshold'       => 5,
        'subquery_depth_limit' => 3,
        'detect_select_star'   => true,
        'detect_missing_where' => true,
        'detect_like_wildcard' => true,
    ],

    // N+1 偵測
    'n1_detection' => [
        'enabled'   => true,
        'threshold' => 2,
    ],

    // 重複查詢偵測
    'duplicate_detection' => [
        'enabled' => true,
    ],

    // Slow Query 追蹤
    'slow_query' => [
        'enabled'      => true,
        'threshold_ms' => (int) env('SQL_MONITOR_SLOW_QUERY_THRESHOLD_MS', 100),
    ],

    // WebSocket 即時推送
    'live_monitor' => [
        'enabled'           => true,
        'broadcast_channel' => 'sql-monitor',
        'max_buffer_size'   => 1000,
    ],

    // Call Stack 收集
    'stack_trace' => [
        'enabled'         => true,
        'limit'           => 20,
        'exclude_vendors' => true,
    ],

    'ide'          => env('SQL_MONITOR_IDE', 'vscode'),
    'route_prefix' => 'sql-monitor',
    'middleware'    => ['web'],
];
```

### 常用 `.env` 設定

```env
# 基本啟用
SQL_MONITOR_ENABLED=true

# storage（推薦保持 sqlite，不需額外連線）
SQL_MONITOR_STORAGE_DRIVER=sqlite

# 靜態分析 log 輸出
SQL_MONITOR_ANALYSE_OUTPUT_PATH=storage/logs/sql-monitor
SQL_MONITOR_ANALYSE_LOG=analyse-{date}.log
SQL_MONITOR_SUGGESTION_LOG=suggestion-{date}.log
SQL_MONITOR_ANALYSE_LOG_FORMAT=text

# 慢查詢閾值（ms）
SQL_MONITOR_SLOW_QUERY_THRESHOLD_MS=100

# IDE 跳轉
SQL_MONITOR_IDE=vscode
```

---

## 動態分析

套件在 `boot()` 時自動監聽 `QueryExecuted` 事件，無需改動應用程式代碼。

### Web Dashboard

訪問 `http://localhost:8000/sql-monitor` 查看：

- 查詢摘要與統計
- N+1 警告面板
- 重複查詢面板
- 完整查詢日誌
- Slow Query 列表

### REST API

```
GET  /sql-monitor/api/queries       查詢列表
GET  /sql-monitor/api/analytics     分析數據
GET  /sql-monitor/api/slow-queries  慢查詢
GET  /sql-monitor/api/stats         統計信息
DELETE /sql-monitor/api/logs        清理日誌
```

### 在程式碼中讀取指標

```php
use LaravelSqlMonitor\Lifecycle\RequestQueryManager;
use LaravelSqlMonitor\Monitor\MetricsCollector;

$manager = app(RequestQueryManager::class);
$count   = $manager->count();
$stats   = $manager->getStats(); // slow_query_count, n1_count, ...

$metrics = app(MetricsCollector::class)->collect();
```

---

## 支援的資料庫

### 動態分析
- MySQL / MariaDB
- PostgreSQL
- SQLite
- SQL Server（基本）

### 靜態分析 — 索引檢查與選擇率計算
- MySQL / MariaDB（完整支援：INFORMATION_SCHEMA + COUNT DISTINCT）
- SQLite（基本：PRAGMA index_list）
- PostgreSQL（待擴展）
- SQL Server（待擴展）

---

## CI/CD 整合

```bash
# 只顯示 warning 以上，有 critical 時 exit code = 1
php artisan sql-monitor:analyse --min-severity=warning --no-index-recommend

# JSON 輸出供後續處理
php artisan sql-monitor:analyse --format=json > analyse.json
```

---

## 模組結構

```
src/
├── StaticAnalysis/
│   ├── Ast/
│   │   ├── AstAnalyser.php              # PHP-Parser 驅動的 AST 掃描
│   │   ├── QueryChainExtractor.php      # 從 AST 節點提取查詢方法鏈
│   │   └── QueryCallSite.php            # 查詢呼叫點資料模型
│   ├── CallSiteAnalyser.php             # 規則引擎（偵測 no-limit / missing-index 等）
│   ├── CallSiteReport.php               # 分析結果資料模型
│   ├── CompositeIndexRecommender.php    # 複合索引建議演算法
│   └── IndexInspector.php               # DB 索引 / cardinality 查詢
│
├── Console/Commands/
│   └── AnalyseQueries.php              # sql-monitor:analyse 命令
│
├── Core/                               # 動態分析核心
│   ├── QueryAnalyzer.php
│   ├── ComplexityDetector.php
│   └── OptimizationSuggester.php
│
├── Lifecycle/
│   ├── N1QueryDetector.php
│   └── DuplicateQueryDetector.php
│
├── Monitor/
│   ├── SlowQueryTracker.php
│   └── LiveQueryMonitor.php
│
├── Storage/
│   ├── SqliteQueryStore.php
│   └── DatabaseQueryStore.php
│
└── Http/
    ├── Controllers/
    └── Routes/
```

---

## License

MIT License © 2026.
