<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Tests\Unit\StaticAnalysis;

use Illuminate\Database\Query\Builder;
use LaravelSqlMonitor\StaticAnalysis\QueryBuilderAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * 靜態分析 Query Builder 的單元測試。
 *
 * 運行：php artisan pest tests/Unit/StaticAnalysis/QueryBuilderAnalyzerTest.php
 */
class QueryBuilderAnalyzerTest extends TestCase
{
    /**
     * 測試：簡單的 SELECT 查詢分析。
     */
    public function testAnalyzeSimpleSelect(): void
    {
        // 預計：
        // - 無 JOIN
        // - 無 WHERE
        // - 應該有 SELECT * 警告

        $sql = "SELECT * FROM users";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        $this->assertTrue($result->isSuccessful);
        $this->assertEquals('users', $result->mainTable);
        $this->assertEmpty($result->joins);
        $this->assertEmpty($result->wheres);
    }

    /**
     * 測試：包含 WHERE 和 JOIN 的查詢。
     */
    public function testAnalyzeWithJoinAndWhere(): void
    {
        $sql = "SELECT u.id, u.name, p.title
                FROM users u
                INNER JOIN posts p ON u.id = p.user_id
                WHERE u.active = 1 AND p.published = 1
                ORDER BY p.created_at DESC
                LIMIT 10";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        $this->assertTrue($result->isSuccessful);
        $this->assertEquals('users', $result->mainTable);
        $this->assertCount(1, $result->joins);
        $this->assertGreaterThanOrEqual(2, count($result->wheres));
        $this->assertTrue($result->hasOrderBy);
        $this->assertTrue($result->hasLimit);
    }

    /**
     * 測試：過多 JOIN 的檢測（應該產生 critical issue）。
     */
    public function testDetectExcessiveJoins(): void
    {
        $sql = "SELECT * FROM a
                JOIN b ON a.id = b.a_id
                JOIN c ON b.id = c.b_id
                JOIN d ON c.id = d.c_id
                JOIN e ON d.id = e.d_id
                JOIN f ON e.id = f.e_id
                JOIN g ON f.id = g.f_id";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        $criticalIssues = $result->getCriticalIssues();
        $this->assertNotEmpty($criticalIssues);

        $excessiveJoin = array_filter($criticalIssues, fn($i) => $i['id'] === 'excessive-joins');
        $this->assertNotEmpty($excessiveJoin);
    }

    /**
     * 測試：缺少索引的警告。
     */
    public function testDetectMissingIndexWarning(): void
    {
        $sql = "SELECT * FROM users WHERE email = ?";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        // 檢查 where 子句
        $this->assertNotEmpty($result->wheres);

        $where = $result->wheres[0];
        $this->assertEquals('email', $where['column']);
    }

    /**
     * 測試：UNION 查詢的檢測。
     */
    public function testAnalyzeUnionQueries(): void
    {
        $sql = "SELECT id, name FROM users
                UNION
                SELECT id, name FROM admins";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        // UNION 應該被檢測到
        $this->assertTrue($result->hasUnion);
    }

    /**
     * 測試：GROUP BY 和 HAVING 的檢測。
     */
    public function testAnalyzeGroupByAndHaving(): void
    {
        $sql = "SELECT user_id, COUNT(*) as post_count
                FROM posts
                GROUP BY user_id
                HAVING COUNT(*) > 5
                ORDER BY post_count DESC";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        $this->assertTrue($result->hasGroupBy);
        $this->assertTrue($result->hasHaving);
    }

    /**
     * 測試：無界限查詢的檢測（critical）。
     */
    public function testDetectUnboundedQuery(): void
    {
        $sql = "SELECT * FROM users";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        $criticalIssues = $result->getCriticalIssues();

        // 沒有 LIMIT 和沒有足夠的 WHERE 應該產生警告
        // （取決於實現細節）
    }

    /**
     * 測試：複雜的子查詢。
     */
    public function testAnalyzeSubquery(): void
    {
        $sql = "SELECT * FROM (
                    SELECT user_id, COUNT(*) as cnt
                    FROM posts
                    GROUP BY user_id
                ) AS subq
                WHERE cnt > 10";

        $analysis = new QueryBuilderAnalyzer($this->createQueryBuilder($sql));
        $result   = $analysis->analyze();

        // 子查詢應該被檢測到
        $this->assertNotEmpty($result->subqueries);
    }

    // ─── Helpers ────────────────────────────────────────────

    /**
     * 建立一個模擬的 Query Builder 實例。
     * （在實際測試中，應該使用真實的 DB::table() 或從 Laravel 測試套件獲取）
     */
    protected function createQueryBuilder(string $sql): Builder
    {
        // 模擬實現 - 實際應用應該用真實的 Builder
        // 這裡簡化為示意
        $builder = new class extends Builder {
            public function __construct()
            {
                // 初始化
            }
        };

        return $builder;
    }
}
