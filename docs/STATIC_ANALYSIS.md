# 靜態分析 — 在執行前檢查查詢

Laravel SQL Monitor 提供強大的**靜態分析**功能，能在 SQL 執行前就檢查 Query Builder 鏈式調用，識別潛在的性能問題和索引缺陷。

## 核心概念

### 動態執行分析 vs 靜態分析

| 方面 | 動態分析 | 靜態分析 |
|------|---------|---------|
| **何時執行** | SQL 執行後 | SQL 執行前 |
| **檢查項目** | 實際執行時間、行數、執行計畫 | 查詢結構、索引存在、複雜度 |
| **用途** | 優化已執行的查詢 | 防止編寫有缺陷的查詢 |
| **性能開銷** | 低（僅在開發環境） | 微乎其微 |

## 快速開始

### 1. 分析 Query Builder 實例

```php
use LaravelSqlMonitor\StaticAnalysis\QueryBuilderAnalyzer;

$query = DB::table('users')
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->where('users.active', true)
    ->where('posts.published_at', '>=', now())
    ->orderBy('posts.created_at', 'desc')
    ->limit(10);

// 方法 1：使用 Macro（推薦）
$analysis = $query->analyzeStatic()->analyze();

// 方法 2：直接使用類
$analyzer = new QueryBuilderAnalyzer($query);
$analysis = $analyzer->analyze();

// 檢查結果
echo $analysis->mainTable;        // "users"
echo count($analysis->joins);     // 1
echo count($analysis->wheres);    // 2
echo $analysis->hasCriticalIssues() ? "有嚴重問題！" : "OK";
```

### 2. 檢查索引命中

```php
use LaravelSqlMonitor\StaticAnalysis\IndexInspector;

$inspector = new IndexInspector();

// 檢查欄位是否有索引
$hasIndex = $inspector->isColumnIndexed('users', 'email');

// 獲取表的所有索引
$indexes = $inspector->getTableIndexes('users');
// 返回：
// [
//     'PRIMARY' => ['columns' => ['id'], 'type' => 'primary', 'unique' => true],
//     'idx_email' => ['columns' => ['email'], 'type' => 'index', 'unique' => true],
//     'idx_active_created' => ['columns' => ['active', 'created_at'], 'type' => 'index', 'unique' => false],
// ]

// 檢查 JOIN 是否優化
$optimizable = $inspector->isJoinOptimizable('users', 'id', 'posts', 'user_id');
```

### 3. 結構分析和優化建議

```php
use LaravelSqlMonitor\StaticAnalysis\StructureAnalyzer;

$query = User::query()
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->join('comments', 'posts.id', '=', 'comments.post_id')
    ->where('users.status', 'active')
    ->orderBy('posts.created_at');

$analysis  = $query->analyzeStatic()->analyze();
$inspector = new IndexInspector();
$structure = new StructureAnalyzer($analysis, $inspector);

// 計算複雜度分數（0-100）
$score = $structure->calculateComplexityScore();
echo "複雜度評分：{$score}/100";

// 估計選擇率（0-1）
$selectivity = $structure->estimateSelectivity();
echo "預計返回 " . round($selectivity * 100) . "% 的資料";

// 估計查詢成本
$cost = $structure->estimateCost();
echo "相對成本：{$cost}";

// 獲取最佳化建議
$suggestions = $structure->getOptimizationSuggestions();
foreach ($suggestions as $suggestion) {
    echo "[{$suggestion['severity']}] {$suggestion['message']}\n";
}

// 生成結構摘要
echo $structure->generateSummary();
// 輸出：FROM users → JOIN (2) → WHERE (1) → ORDER BY → [Complexity: 45/100 - WARNING]
```

## 檢測規則

### 自動檢測的問題

#### 1. **SELECT \* 使用**
```php
// ❌ 警告
$query->select('*')->get();

// ✅ 改善
$query->select('id', 'name', 'email')->get();
```

#### 2. **缺少索引的 WHERE 條件**
```php
// ❌ warning（如果 email 沒有索引）
$query->where('email', 'john@example.com');

// ✅ 建立索引
Schema::table('users', function (Blueprint $table) {
    $table->index('email');
});
```

#### 3. **過多 JOIN（> 5 個）**
```php
// ❌ critical
User::query()
    ->join('posts', ...)
    ->join('comments', ...)
    ->join('likes', ...)
    ->join('shares', ...)
    ->join('tags', ...)
    ->join('categories', ...)  // 第 6 個 JOIN
    ->get();

// ✅ 拆分查詢
$users = User::with('posts.comments', 'posts.likes')->get();
```

#### 4. **無界限查詢**
```php
// ❌ critical
Article::all();  // 無 WHERE、無 LIMIT

// ✅ 加入 LIMIT
Article::limit(100)->get();
```

#### 5. **LIMIT 前沒有 ORDER BY**
```php
// ❌ info
Post::limit(10)->get();  // 結果順序非確定

// ✅ 明確排序
Post::orderBy('created_at', 'desc')->limit(10)->get();
```

#### 6. **JOIN 未索引**
```php
// ❌ warning
// 假設 posts.user_id 沒有索引
User::join('posts', 'users.id', '=', 'posts.user_id')->get();

// ✅ 建立外鍵索引
Schema::table('posts', function (Blueprint $table) {
    $table->index('user_id');  // 或使用外鍵
    // $table->foreign('user_id')->references('id')->on('users');
});
```

## 實際案例

### 案例 1：N+1 模式的靜態檢測

```php
// 應用代碼
$users = User::all();
foreach ($users as $user) {
    echo $user->posts()->count();  // N+1
}

// 靜態分析會檢測到
$analysis = User::query()->analyzeStatic()->analyze();
echo count($analysis->issues);  // 包含 N+1 警告

// 建議
// "Use Eager Loading: User::with('posts')->get()"
```

### 案例 2：複雜 JOIN 的複雜度評分

```php
$analysis = DB::table('users')
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->join('products', 'orders.product_id', '=', 'products.id')
    ->join('categories', 'products.category_id', '=', 'categories.id')
    ->join('reviews', 'products.id', '=', 'reviews.product_id')
    ->join('ratings', 'reviews.id', '=', 'ratings.review_id')
    ->join('comments', 'ratings.id', '=', 'comments.rating_id')
    ->where('users.status', 'active')
    ->where('orders.status', 'completed')
    ->select('users.name', 'products.title', 'reviews.content')
    ->analyzeStatic()
    ->analyze();

$structure = new StructureAnalyzer($analysis);
$score = $structure->calculateComplexityScore();  // 可能得 75+（CRITICAL）

// 建議：拆分查詢或使用視圖
```

### 案例 3：複合索引最佳化

```php
$users = User::query()
    ->where('country', 'US')
    ->where('status', 'active')
    ->where('created_at', '>=', '2024-01-01')
    ->orderBy('created_at', 'desc')
    ->limit(50)
    ->analyzeStatic()
    ->analyze();

$inspector = new IndexInspector();

// 檢查是否有複合索引
$hasCompositeIndex = false;
$indexes = $inspector->getTableIndexes('users');
foreach ($indexes as $index) {
    if (count($index['columns']) === 3 &&
        in_array('country', $index['columns']) &&
        in_array('status', $index['columns']) &&
        in_array('created_at', $index['columns'])) {
        $hasCompositeIndex = true;
        break;
    }
}

if (! $hasCompositeIndex) {
    // 建議建立複合索引
    // Schema::table('users', function (Blueprint $table) {
    //     $table->index(['country', 'status', 'created_at']);
    // });
}
```

## API 參考

### QueryBuilderAnalyzer

```php
$analyzer = new QueryBuilderAnalyzer($query);
$analysis = $analyzer->analyze();

// StaticAnalysisResult 屬性
$analysis->queryBuilderType;   // 'eloquent' | 'query'
$analysis->mainTable;          // 主表名稱
$analysis->joins;              // [{type, table, condition, probability}]
$analysis->wheres;             // [{type, column, operator, value, indexed}]
$analysis->selects;            // [{type, column?, table?}]
$analysis->unions;             // [{type, query}]
$analysis->hasOrderBy;         // bool
$analysis->hasGroupBy;         // bool
$analysis->hasLimit;           // bool
$analysis->hasOffset;          // bool
$analysis->methodChain;        // ['where', 'join', 'orderBy', ...]
$analysis->issues;             // [{id, severity, message}]

// 方法
$analysis->hasCriticalIssues();   // bool
$analysis->getCriticalIssues();   // array
$analysis->getWarnings();         // array
$analysis->getInfos();            // array
```

### IndexInspector

```php
$inspector = new IndexInspector();

$inspector->isColumnIndexed($table, $column);              // bool
$inspector->getTableIndexes($table);                       // array<name, {columns, type, unique}>
$inspector->isJoinOptimizable($t1, $c1, $t2, $c2);       // bool
$inspector->getIndexStats($table);                         // array<stat_name, value>
```

### StructureAnalyzer

```php
$structure = new StructureAnalyzer($analysis, $indexInspector);

$structure->calculateComplexityScore();      // 0-100
$structure->estimateSelectivity();           // 0.0-1.0
$structure->estimateCost();                  // float
$structure->getOptimizationSuggestions();    // array
$structure->generateSummary();               // string
```

## 最佳實踐

### 1. 在控制器中使用靜態分析

```php
class UserController extends Controller
{
    public function index()
    {
        $query = User::query()
            ->with('posts', 'comments')
            ->where('active', true);

        // 在開發環境檢查
        if (app()->isLocal()) {
            $analysis = $query->analyzeStatic()->analyze();

            if ($analysis->hasCriticalIssues()) {
                Log::warning('Query has critical issues', [
                    'issues' => $analysis->getCriticalIssues(),
                ]);
            }
        }

        return $query->paginate();
    }
}
```

### 2. 在測試中驗證查詢結構

```php
// tests/Feature/QueryStructureTest.php
public function test_user_listing_query_is_optimized()
{
    $query = User::query()
        ->where('active', true)
        ->orderBy('created_at', 'desc');

    $analysis = $query->analyzeStatic()->analyze();

    $this->assertFalse($analysis->hasCriticalIssues());
    $this->assertLessThanOrEqual(3, count($analysis->joins));
}
```

### 3. 設置查詢結構監控

```php
// app/Providers/QueryStructureProvider.php
public function boot()
{
    QueryBuilderMacro::register();

    if (app()->isLocal()) {
        // 在開發環境自動檢查每個 Query Builder 調用
        // 可以集成到 IDE 或 CI/CD 流程
    }
}
```

## 限制和注意事項

1. **靜態分析不能取代 EXPLAIN**
   - 靜態分析提供結構檢查，EXPLAIN 提供實際執行計畫
   - 兩者應該配合使用

2. **索引檢查依賴數據庫連接**
   - IndexInspector 需要直接查詢數據庫
   - 支持 MySQL/MariaDB、PostgreSQL、SQLite（需擴展 SQL Server）

3. **Method Chain 追蹤的準確性**
   - 在某些複雜的動態代碼中可能不準確
   - 建議與實際 SQL 執行分析結合

## 常見問題

**Q: 靜態分析會影響性能嗎？**
A: 基本沒有。分析只在開發環境運行，且不涉及 SQL 執行。

**Q: 如何自定義檢測規則？**
A: 繼承 `StructureAnalyzer` 並覆蓋 `detectIssues()` 方法。

**Q: 支持 PostgreSQL 嗎？**
A: 支持基本的 QueryBuilderAnalyzer，但 IndexInspector 需要擴展（目前支持 MySQL/SQLite）。
