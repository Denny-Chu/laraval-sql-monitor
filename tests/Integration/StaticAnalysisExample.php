<?php

/**
 * 靜態分析的實際使用範例。
 * 這個文件展示了如何在實際 Laravel 應用中整合靜態分析。
 *
 * 使用方式：
 *   在 Routes 或 Controller 中調用這些範例
 */

namespace LaravelSqlMonitor\Tests\Integration;

use LaravelSqlMonitor\StaticAnalysis\QueryBuilderAnalyzer;
use LaravelSqlMonitor\StaticAnalysis\IndexInspector;
use LaravelSqlMonitor\StaticAnalysis\StructureAnalyzer;
use Illuminate\Support\Facades\DB;

class StaticAnalysisExample
{
    /**
     * 範例 1：分析簡單的 Query Builder 查詢。
     */
    public static function exampleSimpleQuery(): void
    {
        // ── 建構查詢 ──────────────────────────────────────
        $query = DB::table('users')
            ->where('active', true)
            ->orderBy('created_at', 'desc')
            ->limit(100);

        // ── 靜態分析 ───────────────────────────────────────
        $analyzer = new QueryBuilderAnalyzer($query);
        $analysis = $analyzer->analyze();

        // ── 檢查結果 ───────────────────────────────────────
        echo "=== 簡單查詢分析 ===\n";
        echo "主表：{$analysis->mainTable}\n";
        echo "WHERE 條件數：" . count($analysis->wheres) . "\n";
        echo "有 ORDER BY：" . ($analysis->hasOrderBy ? '是' : '否') . "\n";
        echo "有 LIMIT：" . ($analysis->hasLimit ? '是' : '否') . "\n";

        if ($analysis->hasCriticalIssues()) {
            echo "⚠️  嚴重問題：\n";
            foreach ($analysis->getCriticalIssues() as $issue) {
                echo "  - {$issue['message']}\n";
            }
        }
    }

    /**
     * 範例 2：分析複雜的 JOIN 查詢並檢查索引。
     */
    public static function exampleComplexJoinQuery(): void
    {
        // ── 建構查詢 ───────────────────────────────────────
        $query = DB::table('users')
            ->select('users.id', 'users.name', 'posts.title', 'comments.body')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->join('comments', 'posts.id', '=', 'comments.post_id')
            ->where('users.status', 'active')
            ->where('posts.published', true)
            ->where('comments.approved', true);

        // ── 靜態分析 ───────────────────────────────────────
        $analyzer  = new QueryBuilderAnalyzer($query);
        $analysis  = $analyzer->analyze();
        $inspector = new IndexInspector();
        $structure = new StructureAnalyzer($analysis, $inspector);

        echo "=== 複雜 JOIN 查詢分析 ===\n";
        echo $structure->generateSummary() . "\n\n";

        // ── 檢查索引 ───────────────────────────────────────
        echo "索引檢查：\n";

        foreach ($analysis->joins as $join) {
            $table = $join['table'];
            echo "  JOIN {$table}: ";

            // 檢查 JOIN 條件中的欄位是否有索引
            // 通常應該檢查外鍵欄位（如 user_id, post_id）
            if ($inspector->isColumnIndexed($table, 'id')) {
                echo "✓ 主鍵已索引\n";
            } else {
                echo "✗ 缺少關鍵索引\n";
            }
        }

        // ── 複雜度評分 ───────────────────────────────────
        $complexity = $structure->calculateComplexityScore();
        echo "\n複雜度評分：{$complexity}/100\n";

        if ($complexity >= 70) {
            echo "警告：查詢過於複雜！\n";
        }

        // ── 最佳化建議 ───────────────────────────────────
        echo "\n最佳化建議：\n";
        foreach ($structure->getOptimizationSuggestions() as $suggestion) {
            echo "  [{$suggestion['severity']}] {$suggestion['title']}\n";
            echo "     {$suggestion['message']}\n";
        }
    }

    /**
     * 範例 3：分析 Eloquent Query 並比較動態 vs 靜態分析。
     */
    public static function exampleEloquentQuery(): void
    {
        // ── 使用 Eloquent Builder ──────────────────────
        $query = \App\Models\User::query()
            ->with('posts', 'comments')
            ->where('active', true)
            ->whereHas('posts', function ($q) {
                $q->where('published', true);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // ── 靜態分析 ───────────────────────────────────
        $analyzer = new QueryBuilderAnalyzer($query);
        $analysis = $analyzer->analyze();

        echo "=== Eloquent Query 分析 ===\n";
        echo "Builder 類型：{$analysis->queryBuilderType}\n";
        echo "方法鏈：" . implode(' → ', $analysis->methodChain) . "\n";
        echo "WHERE 條件數：" . count($analysis->wheres) . "\n";

        // ── 動態分析（執行後）──────────────────────────
        // 這是在實際執行查詢後進行的分析
        // ...SQL 執行，QueryListener 捕獲事件...

        // ── 比較 ───────────────────────────────────────
        echo "\n靜態分析早已發現的潛在問題：\n";
        foreach ($analysis->getWarnings() as $warning) {
            echo "  ⚠️  {$warning['message']}\n";
        }
    }

    /**
     * 範例 4：N+1 查詢的靜態檢測（概念演示）。
     */
    public static function exampleN1Detection(): void
    {
        // ── N+1 問題代碼 ───────────────────────────────
        $users = \App\Models\User::all();  // 1 個查詢

        foreach ($users as $user) {
            // 每個 $user->posts 都會執行一次查詢 (N 次)
            // 總共 1 + N 次查詢（N+1）
            echo $user->posts()->count();
        }

        // ── 靜態分析建議 ───────────────────────────────
        echo "\n靜態分析會建議：\n";
        echo "❌ 不推薦：\n";
        echo "  \$users = User::all();\n";
        echo "  foreach (\$users as \$user) { \$user->posts()->count(); }\n";

        echo "\n✅ 推薦：\n";
        echo "  \$users = User::with('posts')->get();\n";
        echo "  foreach (\$users as \$user) { \$user->posts_count; }\n";

        echo "\n✅ 更好的做法（計數）：\n";
        echo "  User::withCount('posts')->get();\n";
    }

    /**
     * 範例 5：在控制器中使用靜態分析進行品質檢查。
     */
    public static function exampleControllerUsage(): void
    {
        echo <<<'PHP'
// app/Http/Controllers/UserController.php

class UserController extends Controller
{
    public function index()
    {
        $query = User::query()
            ->with('posts')
            ->where('active', true)
            ->orderBy('created_at', 'desc');

        // ── 在開發環境進行靜態檢查 ──────────────────
        if (app()->isLocal()) {
            $analyzer = new QueryBuilderAnalyzer($query);
            $analysis = $analyzer->analyze();

            if ($analysis->hasCriticalIssues()) {
                Log::warning('Query has critical issues', [
                    'issues' => $analysis->getCriticalIssues(),
                    'url'    => request()->url(),
                ]);

                // 可以在開發環境中拋出異常強制修復
                if (config('app.debug')) {
                    throw new \RuntimeException('Query optimization failed');
                }
            }

            // 記錄複雜度評分
            $structure = new StructureAnalyzer($analysis);
            Log::info('Query analysis', [
                'complexity' => $structure->calculateComplexityScore(),
                'summary'    => $structure->generateSummary(),
            ]);
        }

        return $query->paginate();
    }
}
PHP;
    }

    /**
     * 範例 6：在測試中驗證查詢結構。
     */
    public static function exampleTestUsage(): void
    {
        echo <<<'PHP'
// tests/Feature/QueryOptimizationTest.php

class QueryOptimizationTest extends TestCase
{
    /**
     * 驗證使用者列表查詢已最佳化。
     */
    public function test_user_listing_query_is_optimized()
    {
        $query = User::query()
            ->with('posts')
            ->where('active', true);

        $analyzer = new QueryBuilderAnalyzer($query);
        $analysis = $analyzer->analyze();

        // ── 斷言 ─────────────────────────────────────
        $this->assertFalse($analysis->hasCriticalIssues());
        $this->assertLessThanOrEqual(3, count($analysis->joins));
        $this->assertFalse($this->hasSelectStar($analysis));

        $structure = new StructureAnalyzer($analysis);
        $this->assertLessThan(50, $structure->calculateComplexityScore());
    }

    /**
     * 驗證 JOIN 欄位有索引。
     */
    public function test_join_columns_are_indexed()
    {
        $inspector = new IndexInspector();

        $this->assertTrue(
            $inspector->isJoinOptimizable('users', 'id', 'posts', 'user_id'),
            'JOIN columns should be indexed'
        );
    }
}
PHP;
    }
}
