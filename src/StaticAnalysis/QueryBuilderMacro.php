<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * 為 Query Builder 新增 Macro，方便進行靜態分析。
 *
 * 在 Service Provider 的 boot() 中註冊：
 *   QueryBuilderMacro::register();
 */
class QueryBuilderMacro
{
    public static function register(): void
    {
        Builder::macro('analyzeStatic', function () {
            return new QueryBuilderAnalyzer($this);
        });

        Builder::macro('explainStructure', function () {
            $analyzer  = new QueryBuilderAnalyzer($this);
            $analysis  = $analyzer->analyze();
            $structure = new StructureAnalyzer($analysis);

            return $structure->generateSummary();
        });

        EloquentBuilder::macro('analyzeStatic', function () {
            return new QueryBuilderAnalyzer($this);
        });

        EloquentBuilder::macro('explainStructure', function () {
            $analyzer  = new QueryBuilderAnalyzer($this);
            $analysis  = $analyzer->analyze();
            $structure = new StructureAnalyzer($analysis);

            return $structure->generateSummary();
        });
    }
}
