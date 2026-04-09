# Laravel SQL Monitor — 完整架構概覽

## 專案成果

**統計數據：**
- 📁 **檔案數量**：40+ 個 PHP 檔案
- 📝 **代碼行數**：3,500+ 行生產代碼
- 🎯 **功能模組**：10 個核心模組
- 🧪 **測試覆蓋**：單元 + 集成測試框架
- 📚 **文檔**：詳細的 API 文檔和範例

---

## 核心架構

### 雙層分析系統

```
┌─────────────────────────────────────────────────────────────┐
│                    Laravel Application                       │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────────┐          ┌──────────────────┐         │
│  │  靜態分析        │          │  動態分析         │         │
│  │  (執行前)        │          │  (執行後)         │         │
│  ├──────────────────┤          ├──────────────────┤         │
│  │QueryBuilderAnaly-│          │  QueryListener    │         │
│  │zer               │          │                  │         │
│  │ ↓                │          │  ↓               │         │
│  │IndexInspector    │          │  QueryAnalyzer   │         │
│  │ ↓                │          │  ↓               │         │
│  │StructureAnalyzer│          │ComplexityDetec-  │         │
│  │                  │          │tor               │         │
│  └──────────────────┘          │  ↓               │         │
│        ↓                        │OptimizationSug-  │         │
│   StaticAnalysisResult         │gester            │         │
│                                └──────────────────┘         │
│                                      ↓                       │
│                              QueryAnalysis                  │
│                                      ↓                       │
│  ┌────────────────────────────────────────────────────┐    │
│  │          Lifecycle 分析 (Request Scoped)            │    │
│  │  ├─ RequestQueryManager (緩衝)                     │    │
│  │  ├─ N1QueryDetector (N+1 檢測)                     │    │
│  │  └─ DuplicateQueryDetector (重複查詢)              │    │
│  └────────────────────────────────────────────────────┘    │
│                                      ↓                       │
│  ┌────────────────────────────────────────────────────┐    │
│  │          監控與持久化                                │    │
│  │  ├─ SlowQueryTracker (Slow Query)                  │    │
│  │  ├─ LiveQueryMonitor (WebSocket 廣播)              │    │
│  │  └─ MetricsCollector (指標彙整)                    │    │
│  └────────────────────────────────────────────────────┘    │
│                                      ↓                       │
│  ┌────────────────────────────────────────────────────┐    │
│  │           Web Dashboard + REST API                 │    │
│  │  ├─ DashboardController (UI 展示)                  │    │
│  │  ├─ ApiController (JSON API)                       │    │
│  │  └─ QueryMonitoringMiddleware (收集統計)           │    │
│  └────────────────────────────────────────────────────┘    │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 模組詳解

### 1️⃣ 核心分析模組 (`Core/`)

#### QueryAnalyzer
- **目的**：解析 SQL 字符串，提取結構信息
- **依賴**：`phpmyadmin/sql-parser`
- **輸出**：`QueryAnalysis` 對象
- **支持**：
  - SELECT / INSERT / UPDATE / DELETE
  - JOIN 分析
  - 子查詢檢測
  - WHERE 條件提取

#### ComplexityDetector
- **目的**：計算查詢複雜度評分（0-100）
- **規則**：
  - SELECT * (10 分)
  - 過多 JOIN (25 分)
  - 深層子查詢 (20 分)
  - 缺少 WHERE 的 UPDATE/DELETE (30 分)
  - LIKE 前導萬用字元 (10 分)
  - 等等...
- **輸出**：`ComplexityResult` (分數 + 嚴重程度 + 警告清單)

#### OptimizationSuggester
- **目的**：基於分析結果產生可操作的優化建議
- **建議類型**：
  - 指定 SELECT 欄位
  - 簡化 JOIN
  - 使用 CTE
  - 添加索引
  - 使用全文檢索
  - Eager Loading 建議
  - 等等...
- **輸出**：`Suggestion[]` 對象陣列

#### StackTraceCollector
- **目的**：收集並過濾 PHP 調用棧
- **功能**：
  - 排除框架代碼
  - IDE 連結生成（VS Code / PhpStorm）
  - 限制棧深度

---

### 2️⃣ Request Lifecycle 分析模組 (`Lifecycle/`)

#### RequestQueryManager (單例)
- **作用**：在單個 HTTP 請求中緩衝所有查詢
- **功能**：
  - `add(QueryRecord)` - 添加查詢記錄
  - `groupByNormalizedSql()` - 按正規化 SQL 分組
  - `groupByFingerprint()` - 按指紋分組
  - `getStats()` - 取得請求級統計
- **存儲量**：最多 1000 條查詢（可配置）

#### N1QueryDetector
- **原理**：同一正規化 SQL 執行 ≥ 2 次視為 N+1
- **檢測範圍**：
  - 典型的 Eloquent 關係加載模式
  - 循環中的查詢
- **輸出**：`N1Pattern[]` - 包含建議和浪費時間估算

#### DuplicateQueryDetector
- **原理**：完全相同的 SQL + 參數指紋
- **用途**：識別可快取的重複查詢
- **輸出**：`DuplicateGroup[]` - 包含節省時間估算

---

### 3️⃣ 監控模組 (`Monitor/`)

#### SlowQueryTracker
- **監控對象**：執行時間超過閾值（預設 100ms）
- **動作**：
  - 存儲到 SQLite
  - 記錄到 Laravel Log
  - 觸發 SlowQueryDetectedEvent
- **持久化**：通過 QueryStoreInterface

#### LiveQueryMonitor
- **方式**：通過 Laravel 事件系統廣播
- **事件類型**：
  - `QueryCapturedEvent` - 每條查詢
  - `SlowQueryDetectedEvent` - 慢查詢
  - `N1PatternDetectedEvent` - N+1 檢測
- **前端接收**：Laravel Echo + WebSocket

#### MetricsCollector
- **彙整**：所有分析結果
- **輸出**：
  - `collect()` - 完整報告（用於 Dashboard）
  - `quickSummary()` - 摘要（用於 Response Header）

---

### 4️⃣ 靜態分析模組 (`StaticAnalysis/`) ✨ 新增

#### QueryBuilderAnalyzer
- **對象**：Query Builder 或 Eloquent Builder 實例
- **時機**：**執行前** (無需 SQL)
- **提取信息**：
  - 主表
  - JOIN 清單
  - WHERE 條件
  - SELECT 欄位
  - 方法鏈
- **輸出**：`StaticAnalysisResult`
- **Macro 註冊**：
  ```php
  $query->analyzeStatic()->analyze();
  $query->explainStructure();  // 簡短摘要
  ```

#### IndexInspector
- **功能**：檢查數據庫實際索引
- **支持**：
  - MySQL / MariaDB (via INFORMATION_SCHEMA)
  - SQLite (via PRAGMA)
  - PostgreSQL (待擴展)
- **方法**：
  - `isColumnIndexed($table, $column)`
  - `getTableIndexes($table)`
  - `isJoinOptimizable($t1, $c1, $t2, $c2)`
  - `getIndexStats($table)`

#### StructureAnalyzer
- **目的**：計算查詢結構的複雜度和成本
- **功能**：
  - `calculateComplexityScore()` - 0-100 評分
  - `estimateSelectivity()` - 選擇率 0-1
  - `estimateCost()` - 相對成本
  - `getOptimizationSuggestions()` - 優化建議
  - `generateSummary()` - 結構摘要

---

### 5️⃣ Web 層 (`Http/`)

#### DashboardController
- **路由**：`GET /sql-monitor/`
- **視圖**：`dashboard.blade.php` (Tailwind CSS)
- **展示**：
  - 摘要卡片（查詢數、時間、慢查詢）
  - N+1 警告面板
  - 重複查詢面板
  - 完整查詢日誌

#### ApiController
- **路由**：`GET /sql-monitor/api/*`
- **端點**：
  - `/queries` - 查詢列表
  - `/analytics` - 分析數據
  - `/slow-queries` - 慢查詢
  - `/stats` - 統計
  - `DELETE /logs` - 清理日誌

#### QueryMonitoringMiddleware
- **時機**：HTTP 請求結束時
- **動作**：
  - 收集統計信息
  - 添加 Response Header
  - 廣播 N+1 和重複查詢警告

---

### 6️⃣ 存儲層 (`Storage/`)

#### QueryStoreInterface
- **抽象**：統一的存儲接口
- **方法**：
  - `persist(QueryRecord)`
  - `persistBatch(QueryRecord[])`
  - `query(filters, limit, offset)`
  - `slowQueries(limit)`
  - `cleanup(olderThanHours)`
  - `truncate()`
  - `stats()`

#### SqliteQueryStore
- **實現**：SQLite 數據庫存儲
- **自動表創建**：`sql_monitor_logs` 表
- **列**：query_id, sql, bindings, execution_time_ms, complexity_score, 等
- **索引**：executed_at, execution_time_ms, is_slow, query_type
- **可擴展**：可實作其他 Driver（MySQL、PostgreSQL）

---

## 數據流示例

### 執行流程 1：動態分析 (SQL 執行)

```
1. 應用代碼執行 SQL
   ↓
2. QueryListener 捕獲 QueryExecuted 事件
   ↓
3. 提取：SQL、綁定、執行時間、連線、棧信息
   ↓
4. QueryAnalyzer 解析 SQL 結構
   ↓
5. ComplexityDetector 計算複雜度評分
   ↓
6. OptimizationSuggester 生成建議
   ↓
7. 創建 QueryRecord 對象
   ↓
8. RequestQueryManager 緩衝
   ↓
9. SlowQueryTracker 檢查是否超過閾值
   ↓
10. LiveQueryMonitor 廣播事件
   ↓
11. 中間件收集統計、偵測 N+1 和重複
   ↓
12. SqliteQueryStore 持久化
```

### 執行流程 2：靜態分析 (Query Builder)

```
1. 應用代碼構建 Query Builder
   ↓
2. 調用 $query->analyzeStatic()
   ↓
3. QueryBuilderAnalyzer 通過 Reflection 提取 Builder 狀態
   ↓
4. StructureAnalyzer 分析結構
   ↓
5. IndexInspector 查詢數據庫索引情況
   ↓
6. 計算複雜度、選擇率、成本
   ↓
7. 生成最佳化建議
   ↓
8. 返回 StaticAnalysisResult
   ↓
9. 應用可記錄警告或拋出異常（開發環境）
```

---

## 配置體系

### `config/sql-monitor.php`

```php
[
    'enabled'                       => true,
    'environments'                  => ['local', 'testing'],
    'storage.driver'                => 'sqlite',
    'complexity.join_threshold'     => 5,
    'complexity.subquery_depth_limit' => 3,
    'n1_detection.threshold'        => 2,
    'slow_query.threshold_ms'       => 100,
    'live_monitor.enabled'          => true,
    'stack_trace.enabled'           => true,
    'ide'                           => 'vscode',
    'route_prefix'                  => 'sql-monitor',
    'middleware'                    => ['web'],
]
```

---

## 事件系統

### 廣播事件（WebSocket）

1. **QueryCapturedEvent**
   - 何時：每條查詢完成
   - 包含：id, sql, executionTimeMs, complexity
   - 用途：實時監控面板

2. **SlowQueryDetectedEvent**
   - 何時：查詢超過閾值
   - 包含：id, sql, stackTrace
   - 用途：警告通知

3. **N1PatternDetectedEvent**
   - 何時：請求結束時偵測到 N+1
   - 包含：normalizedSql, count, suggestion
   - 用途：開發者警告

---

## 使用場景

### 場景 1：開發環境品質檢查

```php
// 自動檢查查詢結構
$query = User::with('posts')
    ->where('active', true)
    ->analyzeStatic();

$analysis = $query->analyze();

if ($analysis->hasCriticalIssues()) {
    Log::warning('Query issues detected', $analysis->getCriticalIssues());
}
```

### 場景 2：性能監控和告警

```php
// 自動追蹤慢查詢並記錄
// SlowQueryTracker 自動寫入 log 和 SQLite

// 可在 Dashboard 查看或通過 API 獲取
```

### 場景 3：測試驗證

```php
// 在測試中驗證查詢最佳化
public function test_query_is_optimized()
{
    $query = User::with('posts')->where('active', true);
    $analysis = $query->analyzeStatic()->analyze();

    $this->assertFalse($analysis->hasCriticalIssues());
}
```

### 場景 4：生產環境監控

```php
// 通過 Middleware 在 Response Header 包含統計
// X-Sql-Monitor-Query-Count: 15
// X-Sql-Monitor-Total-Time: 245.5
// X-Sql-Monitor-Slow-Count: 1
// X-Sql-Monitor-N1-Count: 1
```

---

## 性能考量

### 開銷估算

| 操作 | 開銷 | 說明 |
|------|------|------|
| 動態分析（per query） | 2-5ms | 包括解析、複雜度計算 |
| 靜態分析（per query builder） | < 1ms | Reflection 操作 |
| 索引檢查（per table） | 10-50ms | 數據庫查詢（有快取） |
| 生成建議 | 1-2ms | 規則匹配 |
| 持久化（SQLite） | 5-20ms | 異步寫入 |

### 優化策略

1. **只在開發環境啟用** - 生產環境用 Laravel Pulse
2. **快取索引信息** - 避免重複數據庫查詢
3. **異步持久化** - 不阻塞請求
4. **查詢緩衝限制** - 最多 1000 條/請求

---

## 擴展點

### 自定義 Complexity 規則

```php
class CustomComplexityDetector extends ComplexityDetector
{
    protected function detectIssues(QueryAnalysis $analysis): array
    {
        $issues = parent::detectIssues($analysis);

        // 添加自定義規則
        if ($analysis->hasUnion && count($analysis->joins) > 0) {
            $issues[] = [
                'id' => 'union-with-joins',
                'message' => 'UNION with JOINs: verify correctness',
                'severity' => 'info',
            ];
        }

        return $issues;
    }
}
```

### 自定義存儲 Driver

```php
class PostgresQueryStore implements QueryStoreInterface
{
    public function persist(QueryRecord $record): void
    {
        // 實現 PostgreSQL 持久化
    }

    // ... 實現其他方法
}
```

---

## 路線圖

### Phase 1 ✅ 完成
- [x] 動態 SQL 分析
- [x] 靜態 Query Builder 分析
- [x] 複雜度評分
- [x] 優化建議
- [x] N+1 和重複查詢檢測
- [x] Slow Query 追蹤
- [x] Web Dashboard

### Phase 2 🚧 規劃中
- [ ] Larastan / PHPStan 整合
- [ ] AI 驅動的建議（GPT）
- [ ] Elasticsearch 支持
- [ ] 分佈式追蹤（OpenTelemetry）
- [ ] 性能基準線比較
- [ ] 自動優化提案

### Phase 3 💭 概念
- [ ] 與 Forge 整合
- [ ] 機器學習 Query 異常檢測
- [ ] 自動索引建議執行
- [ ] Query Plan Cache

---

## 結論

Laravel SQL Monitor 提供了一個全面的 SQL 查詢監控和優化解決方案，包括：

✨ **動態分析** - 監控運行中的查詢
✨ **靜態分析** - 在編寫代碼時提前檢查
✨ **索引檢查** - 驗證數據庫索引
✨ **性能估算** - 預測查詢成本
✨ **開發者體驗** - 詳細的建議和 IDE 整合

完全開源，易於擴展，適合各種規模的 Laravel 應用。
