<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Tests\Unit\StaticAnalysis;

use LaravelSqlMonitor\StaticAnalysis\Ast\AstAnalyser;
use LaravelSqlMonitor\StaticAnalysis\CallSiteAnalyser;
use LaravelSqlMonitor\StaticAnalysis\IndexInspector;
use PHPUnit\Framework\TestCase;

class CallSiteAnalyserTest extends TestCase
{
    public function testIgnoresClosureWhereForIndexChecksAndNormalizesAliasedTable(): void
    {
        $source = <<<'PHP'
<?php

class UpsellCampaignItemRepository
{
    public function getItemsByMainProductIds(int $marketType, ?int $pageSize = null)
    {
        return DB::table('upsell_campaign AS uc')
            ->where('status', 1)
            ->where(fn ($query) => $query->where('market_type', $marketType))
            ->paginate($pageSize);
    }
}
PHP;

        $sites = (new AstAnalyser())->analyseSource($source, 'UpsellCampaignItemRepository.php');

        $this->assertCount(1, $sites);

        $inspector = new class extends IndexInspector {
            public array $calls = [];

            public function __construct() {}

            public function isColumnIndexed(string $table, string $column): bool
            {
                $this->calls[] = [$table, $column];

                return true;
            }
        };

        $report = (new CallSiteAnalyser($inspector))->analyse($sites[0]);

        $this->assertSame('upsell_campaign', $report->primaryTable);
        $this->assertSame([['upsell_campaign', 'status']], $inspector->calls);
        $this->assertSame([], array_values(array_filter(
            $report->issues,
            fn(array $issue): bool => $issue['code'] === 'missing-index'
        )));
    }

    public function testUsesEloquentRootClassToInferPrimaryTable(): void
    {
        $source = <<<'PHP'
<?php

class UserRepository
{
    public function changePasswordById(int $id)
    {
        return User::find($id);
    }
}
PHP;

        $sites = (new AstAnalyser())->analyseSource($source, 'UserRepository.php');

        $this->assertCount(1, $sites);

        $report = (new CallSiteAnalyser())->analyse($sites[0]);
        $issueCodes = array_column($report->issues, 'code');

        $this->assertSame('users', $report->primaryTable);
        $this->assertNotContains('no-where', $issueCodes);
        $this->assertNotContains('no-limit', $issueCodes);
        $this->assertNotContains('n1-risk', $issueCodes);
    }

    public function testIgnoresDbRawExpressionsInsideSelectClauses(): void
    {
        $source = <<<'PHP'
<?php

class StoreShippingMethodRepository
{
    public function getConfigurableShippingMethods(int $storeId)
    {
        return DB::table('shipping_method_option')
            ->where('shipping_method_option.status', 1)
            ->select([
                'shipping_method_option.id',
                DB::raw('COALESCE(store_shipping_method.status, 2) as status'),
            ])
            ->get();
    }
}
PHP;

        $sites = (new AstAnalyser())->analyseSource($source, 'StoreShippingMethodRepository.php');

        $this->assertCount(1, $sites);
        $this->assertSame(['table'], array_map(
            static fn($site) => $site->rootMethod,
            $sites,
        ));

        $report = (new CallSiteAnalyser())->analyse($sites[0]);
        $issueCodes = array_column($report->issues, 'code');

        $this->assertNotContains('select-star', $issueCodes);
        $this->assertNotContains('no-where', $issueCodes);
    }
}