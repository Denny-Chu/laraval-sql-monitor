<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Tests\Unit\StaticAnalysis;

use LaravelSqlMonitor\StaticAnalysis\Ast\AstAnalyser;
use LaravelSqlMonitor\StaticAnalysis\CallSiteAnalyser;
use LaravelSqlMonitor\StaticAnalysis\CallSiteReport;
use LaravelSqlMonitor\StaticAnalysis\CompositeIndexRecommender;
use LaravelSqlMonitor\StaticAnalysis\IndexInspector;
use PHPUnit\Framework\TestCase;

class CompositeIndexRecommenderTest extends TestCase
{
    // ─── 基本等值欄位排序（按選擇率 DESC）──────────────────

    public function testOrdersEqualityColumnsBySelectivityDescending(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class OrderRepository
{
    public function find($userId, $status)
    {
        return DB::table('orders')
            ->where('status', $status)     // 低選擇率
            ->where('user_id', $userId)    // 高選擇率
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'orders' => [
                'rows'          => 100000,
                'distinct'      => ['user_id' => 85000, 'status' => 5],
                'indexes'       => [],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        $this->assertArrayHasKey('orders', $result);
        $this->assertCount(1, $result['orders']);
        $this->assertSame(['user_id', 'status'], $result['orders'][0]['columns']);
    }

    // ─── Range 欄位必須放在等值欄位之後 ────────────────────

    public function testRangeColumnAfterEqualityColumns(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class EventRepository
{
    public function fetch()
    {
        return DB::table('events')
            ->where('user_id', 1)
            ->where('created_at', '>', '2024-01-01')
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'events' => [
                'rows'     => 10000,
                'distinct' => ['user_id' => 500],
                'indexes'  => [],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        $this->assertArrayHasKey('events', $result);
        $this->assertSame(['user_id', 'created_at'], $result['events'][0]['columns']);
        $this->assertSame('created_at', $result['events'][0]['range_col']);
    }

    // ─── ORDER BY 欄位放在索引尾端 ─────────────────────────

    public function testOrderByColumnAppendedToTail(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class PostRepository
{
    public function fetch()
    {
        return DB::table('posts')
            ->where('author_id', 1)
            ->orderBy('published_at', 'desc')
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'posts' => [
                'rows'     => 5000,
                'distinct' => ['author_id' => 200],
                'indexes'  => [],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        $this->assertArrayHasKey('posts', $result);
        $this->assertSame(['author_id', 'published_at'], $result['posts'][0]['columns']);
    }

    // ─── 現有索引已覆蓋時不應產生建議 ──────────────────────

    public function testSkipsWhenExistingIndexAlreadyCoversPattern(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class UserRepository
{
    public function find($email)
    {
        return DB::table('users')
            ->where('email', $email)
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'users' => [
                'rows'     => 10000,
                'distinct' => ['email' => 9500],
                'indexes'  => [
                    'users_email_unique' => ['columns' => ['email'], 'type' => 'index', 'unique' => true],
                ],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        $this->assertArrayNotHasKey('users', $result);
    }

    // ─── 被取代的現有索引應列出 ────────────────────────────

    public function testReportsReplaceableExistingIndex(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class OrderRepository
{
    public function find($userId, $status)
    {
        return DB::table('orders')
            ->where('user_id', $userId)
            ->where('status', $status)
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'orders' => [
                'rows'     => 10000,
                'distinct' => ['user_id' => 8000, 'status' => 5],
                'indexes'  => [
                    'PRIMARY'       => ['columns' => ['id'], 'type' => 'primary', 'unique' => true],
                    'idx_user'      => ['columns' => ['user_id'], 'type' => 'index', 'unique' => false],
                ],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        $this->assertArrayHasKey('orders', $result);
        $this->assertSame(['user_id', 'status'], $result['orders'][0]['columns']);
        $this->assertSame(['idx_user'], $result['orders'][0]['replaces']);
    }

    // ─── PRIMARY 不會被列為可取代 ─────────────────────────

    public function testPrimaryIndexNeverReplaceable(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class Foo
{
    public function run()
    {
        return DB::table('users')
            ->where('id', 1)
            ->where('status', 'active')
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'users' => [
                'rows'     => 10000,
                'distinct' => ['id' => 10000, 'status' => 3],
                'indexes'  => [
                    'PRIMARY' => ['columns' => ['id'], 'type' => 'primary', 'unique' => true],
                ],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        // 建議產生（id, status），但 PRIMARY 不應出現在 replaces
        $this->assertArrayHasKey('users', $result);
        $this->assertNotContains('PRIMARY', $result['users'][0]['replaces']);
    }

    // ─── LIKE 前導萬用字元不納入建議 ───────────────────────

    public function testLeadingWildcardLikeNotRecommended(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class SearchRepository
{
    public function run()
    {
        return DB::table('articles')
            ->where('title', 'LIKE', '%foo%')
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'articles' => [
                'rows'     => 1000,
                'distinct' => ['title' => 900],
                'indexes'  => [],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        // 無可索引欄位 → 無建議
        $this->assertArrayNotHasKey('articles', $result);
    }

    // ─── LIKE 'abc%' 前綴可走索引 ─────────────────────────

    public function testPrefixLikeIsIndexable(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class SearchRepository
{
    public function run()
    {
        return DB::table('articles')
            ->where('slug', 'LIKE', 'post-%')
            ->get();
    }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'articles' => [
                'rows'     => 1000,
                'distinct' => ['slug' => 950],
                'indexes'  => [],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        $this->assertArrayHasKey('articles', $result);
        $this->assertSame(['slug'], $result['articles'][0]['columns']);
    }

    // ─── 多個 call site 同 pattern → 頻率累加 ────────────

    public function testPatternFrequencyAccumulation(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class R
{
    public function a() { return DB::table('orders')->where('user_id', 1)->get(); }
    public function b() { return DB::table('orders')->where('user_id', 2)->get(); }
    public function c() { return DB::table('orders')->where('user_id', 3)->get(); }
}
PHP
        );

        $inspector = new FakeIndexInspector([
            'orders' => [
                'rows'     => 10000,
                'distinct' => ['user_id' => 8000],
                'indexes'  => [],
            ],
        ]);

        $result = (new CompositeIndexRecommender($inspector))->recommend($reports);

        $this->assertSame(3, $result['orders'][0]['frequency']);
    }

    // ─── 無 inspector 時仍可基本運作（選擇率未知）───────

    public function testWorksWithoutInspector(): void
    {
        $reports = $this->buildReportsFromSource(<<<'PHP'
<?php

class R
{
    public function run()
    {
        return DB::table('orders')
            ->where('user_id', 1)
            ->where('status', 'pending')
            ->get();
    }
}
PHP
        );

        $result = (new CompositeIndexRecommender(null))->recommend($reports);

        $this->assertArrayHasKey('orders', $result);
        // 選擇率未知 → 字母序：status, user_id
        $this->assertSame(['status', 'user_id'], $result['orders'][0]['columns']);
    }

    // ─── Helpers ───────────────────────────────────────────

    /**
     * @return CallSiteReport[]
     */
    private function buildReportsFromSource(string $source): array
    {
        $sites     = (new AstAnalyser())->analyseSource($source, 'Test.php');
        $analyser  = new CallSiteAnalyser(null); // 不做 index check
        return array_map(fn($s) => $analyser->analyse($s), $sites);
    }
}

// ─── 假的 IndexInspector：用 in-memory 資料取代 DB 查詢 ──────

class FakeIndexInspector extends IndexInspector
{
    public function __construct(private array $data)
    {
        // 不呼叫 parent::__construct，避免建立真實 DB 連線
    }

    public function getTableIndexes(string $table): array
    {
        return $this->data[$table]['indexes'] ?? [];
    }

    public function getTableRowCount(string $table): int
    {
        return $this->data[$table]['rows'] ?? 0;
    }

    public function getColumnDistinctCount(string $table, string $column): int
    {
        return $this->data[$table]['distinct'][$column] ?? 0;
    }

    public function getColumnSelectivity(string $table, string $column): ?float
    {
        $rows     = $this->getTableRowCount($table);
        $distinct = $this->getColumnDistinctCount($table, $column);

        if ($rows <= 0 || $distinct <= 0) {
            return null;
        }

        return min(1.0, $distinct / $rows);
    }
}
