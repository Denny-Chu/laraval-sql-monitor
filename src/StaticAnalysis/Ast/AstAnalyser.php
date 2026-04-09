<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis\Ast;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;

/**
 * 解析一個 PHP 檔案，找出所有的 DB:: / Model:: 查詢呼叫。
 */
class AstAnalyser
{
    private \PhpParser\Parser $parser;
    private NodeFinder        $finder;
    private QueryChainExtractor $extractor;

    public function __construct()
    {
        $this->parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder    = new NodeFinder();
        $this->extractor = new QueryChainExtractor();
    }

    /**
     * 分析一個 PHP 檔案，回傳所有找到的 QueryCallSite。
     *
     * @param  string $filePath 絕對路徑
     * @return QueryCallSite[]
     */
    public function analyseFile(string $filePath): array
    {
        $source = @file_get_contents($filePath);

        if ($source === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        return $this->analyseSource($source, $filePath);
    }

    /**
     * 分析 PHP 原始碼字串，回傳所有找到的 QueryCallSite。
     *
     * @param  string $source   PHP 原始碼
     * @param  string $filePath 僅用於顯示
     * @return QueryCallSite[]
     */
    public function analyseSource(string $source, string $filePath = '<string>'): array
    {
        try {
            $stmts = $this->parser->parse($source);
        } catch (Error $e) {
            throw new \RuntimeException("Parse error in {$filePath}: " . $e->getMessage());
        }

        if ($stmts === null) {
            return [];
        }

        // 解析完整命名空間（讓 Name 節點有 namespacedName 屬性）
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        return $this->extractCallSites($stmts, $filePath);
    }

    // ─── 提取 Call Sites ─────────────────────────────────

    /**
     * 遍歷 AST，找到所有的查詢呼叫點，並注入所在類別/方法資訊。
     *
     * @return QueryCallSite[]
     */
    private function extractCallSites(array $stmts, string $filePath): array
    {
        $callSites = [];

        // 尋找所有 StaticCall（作為各鏈的底部根節點）
        $staticCalls = $this->finder->findInstanceOf($stmts, StaticCall::class);

        foreach ($staticCalls as $staticCall) {
            // 嘗試從此 StaticCall 向上找最外層的 MethodCall
            // 策略：直接對 staticCall 的所有父節點進行處理
            // 這裡用另一種方法：掃描所有 MethodCall，然後看其最底層是否是目標 StaticCall
        }

        // 更好的策略：找所有 MethodCall 和 StaticCall，
        // 對每個嘗試提取；避免重複（只保留最外層的）
        $allCalls = array_merge(
            $this->finder->findInstanceOf($stmts, MethodCall::class),
            $this->finder->findInstanceOf($stmts, StaticCall::class),
        );

        // 用 spl_object_id 標記已處理的「根節點」，避免重複
        $processedRoots = [];

        foreach ($allCalls as $callNode) {
            $site = $this->extractor->extract($callNode, $filePath);

            if ($site === null) {
                continue;
            }

            // 用 filePath + startLine 作為根的唯一識別（避免子節點重複觸發）
            $key = "{$site->filePath}:{$site->startLine}:{$site->rootType}:{$site->rootMethod}";

            if (isset($processedRoots[$key])) {
                // 若已有相同根，保留結束行號更大的（即更完整的外層）
                if ($site->endLine > $processedRoots[$key]->endLine) {
                    $processedRoots[$key] = $site;
                }
                continue;
            }

            $processedRoots[$key] = $site;
        }

        $callSites = array_values($processedRoots);

        // 回填所在類別和方法資訊
        $this->enrichWithContext($stmts, $callSites);

        // 按行號排序
        usort($callSites, fn($a, $b) => $a->startLine <=> $b->startLine);

        return $callSites;
    }

    /**
     * 回填每個 CallSite 的所在類別和方法名稱。
     */
    private function enrichWithContext(array $stmts, array &$callSites): void
    {
        $classes = $this->finder->findInstanceOf($stmts, Class_::class);

        foreach ($classes as $class) {
            $className = $class->name ? $class->name->toString() : null;

            foreach ($class->getMethods() as $classMethod) {
                $methodName = $classMethod->name->toString();
                $start      = $classMethod->getStartLine();
                $end        = $classMethod->getEndLine();

                foreach ($callSites as $site) {
                    if ($site->startLine >= $start && $site->startLine <= $end) {
                        $site->className  = $className;
                        $site->methodName = $methodName;
                    }
                }
            }
        }
    }
}
