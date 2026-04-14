<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 啟用監控
    |--------------------------------------------------------------------------
    | 設為 false 將完全停用所有監控功能。
    | 建議僅在 local / testing 環境啟用。
    |
    | .env: SQL_MONITOR_ENABLED=true
    */
    'enabled' => env('SQL_MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | 允許執行的環境
    |--------------------------------------------------------------------------
    */
    'environments' => ['local', 'testing'],

    /*
    |--------------------------------------------------------------------------
    | 監控的資料庫連線（白名單）
    |--------------------------------------------------------------------------
    | 指定要監控的連線名稱陣列，空陣列 = 監控所有連線。
    |
    | 範例（只監控 mysql）：
    |   'connections' => ['mysql'],
    */
    'connections' => [],

    /*
    |--------------------------------------------------------------------------
    | 排除監控的連線（黑名單）
    |--------------------------------------------------------------------------
    | 這些連線的查詢永遠不會被記錄，優先於 connections 白名單。
    |
    | 常見用途：
    |   1. storage.driver = database 且連線為 MySQL 時，
    |      若不想用獨立連線，可將 storage 連線名稱加到這裡，
    |      避免 persist() 觸發 QueryExecuted → 無限迴圈。
    |   2. IndexInspector 使用的連線（靜態分析查 INFORMATION_SCHEMA 時）。
    |
    | 範例：
    |   'excluded_connections' => ['sql_monitor_storage'],
    */
    'excluded_connections' => [],

    /*
    |--------------------------------------------------------------------------
    | 數據存儲
    |--------------------------------------------------------------------------
    | driver = sqlite（預設）：
    |   使用獨立 SQLite 檔案，完全不影響應用程式 MySQL，零迴圈風險。
    |
    | driver = database（使用既有 MySQL/PostgreSQL 連線）：
    |   強烈建議在 config/database.php 設定一條獨立連線（如 sql_monitor_mysql），
    |   並將 connection 指向它，以確保 storage 查詢自動被 QueryListener 過濾。
    |
    | .env:
    |   SQL_MONITOR_STORAGE_DRIVER=sqlite       # sqlite | database
    |   SQL_MONITOR_STORAGE_CONNECTION=         # driver=database 時使用的連線名稱
    |   SQL_MONITOR_STORAGE_DATABASE=           # SQLite 路徑（空 = 預設路徑）
    */
    'storage' => [
        'driver'          => env('SQL_MONITOR_STORAGE_DRIVER', 'sqlite'),
        'database'        => env('SQL_MONITOR_STORAGE_DATABASE') ?: null,
        'connection'      => env('SQL_MONITOR_STORAGE_CONNECTION') ?: null,
        'table'           => 'sql_monitor_logs',
        'retention_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Statement 複雜度分析
    |--------------------------------------------------------------------------
    */
    'complexity' => [
        'enabled'              => true,
        'join_threshold'       => 5,        // JOIN 數量超過此值標記為 warning
        'subquery_depth_limit' => 3,        // 子查詢巢狀深度
        'detect_select_star'   => true,     // 檢測 SELECT *
        'detect_missing_where' => true,     // 檢測缺少 WHERE 的 UPDATE/DELETE
        'detect_like_wildcard' => true,     // 檢測 LIKE '%...' 前導萬用字元
    ],

    /*
    |--------------------------------------------------------------------------
    | N+1 查詢偵測
    |--------------------------------------------------------------------------
    */
    'n1_detection' => [
        'enabled'   => true,
        'threshold' => 2,                   // 相同正規化 SQL 執行 ≥ 此次數即視為 N+1
    ],

    /*
    |--------------------------------------------------------------------------
    | 重複查詢偵測
    |--------------------------------------------------------------------------
    */
    'duplicate_detection' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow Query 追蹤
    |--------------------------------------------------------------------------
    | threshold_ms 建議透過 env 調整，不同環境的效能基準往往不同。
    |
    | .env: SQL_MONITOR_SLOW_QUERY_THRESHOLD_MS=100
    */
    'slow_query' => [
        'enabled'      => true,
        'threshold_ms' => (int) env('SQL_MONITOR_SLOW_QUERY_THRESHOLD_MS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Query Monitor（WebSocket 即時推送）
    |--------------------------------------------------------------------------
    */
    'live_monitor' => [
        'enabled'           => true,
        'broadcast_channel' => 'sql-monitor',
        'max_buffer_size'   => 1000,        // 單次請求最大收集數量
    ],

    /*
    |--------------------------------------------------------------------------
    | Stack Trace 收集
    |--------------------------------------------------------------------------
    */
    'stack_trace' => [
        'enabled'         => true,
        'limit'           => 20,            // 最多保留幾層呼叫棧
        'exclude_vendors' => true,          // 排除 vendor/ 目錄的 frame
    ],

    /*
    |--------------------------------------------------------------------------
    | IDE 跳轉整合
    |--------------------------------------------------------------------------
    | .env: SQL_MONITOR_IDE=vscode   # vscode | phpstorm | sublime
    */
    'ide' => env('SQL_MONITOR_IDE', 'vscode'),

    /*
    |--------------------------------------------------------------------------
    | 路由前綴與中間件
    |--------------------------------------------------------------------------
    */
    'route_prefix' => 'sql-monitor',
    'middleware'    => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Dashboard 授權 Gate
    |--------------------------------------------------------------------------
    | 回傳 true 表示允許訪問。null 代表不做額外授權檢查。
    */
    'gate' => null,

];
