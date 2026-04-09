<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis\Ast;

/**
 * 代表一個在原始碼中找到的查詢呼叫點（Call Site）。
 *
 * 例如：
 *   DB::table('users')->where('active', 1)->join(...)->first()
 *   User::with('posts')->where(...)->get()
 *   Post::query()->join(...)->orderBy(...)->paginate()
 */
class QueryCallSite
{
    /** 呼叫的根類型：db | eloquent | query_builder */
    public string $rootType;

    /** 根方法：table / select / raw / query */
    public string $rootMethod;

    /** 根方法的引數（例如 table 名稱、Model 類別名稱） */
    public array $rootArgs;

    /** 方法鏈 [{method, args, line}] */
    public array $chain = [];

    /** 偵測到的 JOIN 清單 [{type, table, condition, line}] */
    public array $joins = [];

    /** WHERE 條件清單 [{column, operator, line}] */
    public array $wheres = [];

    /** SELECT 欄位 */
    public array $selects = [];

    /** with() / eager load 清單 */
    public array $withs = [];

    /** 是否有 orderBy */
    public bool $hasOrderBy = false;

    /** 是否有 groupBy */
    public bool $hasGroupBy = false;

    /** 是否有 limit */
    public bool $hasLimit = false;

    /** 是否有 union */
    public bool $hasUnion = false;

    /** 最終執行方法：get / first / paginate / count / exists ... */
    public ?string $terminalMethod = null;

    /** 所在檔案路徑 */
    public string $filePath;

    /** 呼叫起始行號 */
    public int $startLine;

    /** 呼叫結束行號 */
    public int $endLine;

    /** 所在類別名稱（若有） */
    public ?string $className = null;

    /** 所在方法名稱（若有） */
    public ?string $methodName = null;

    // ─── Helpers ────────────────────────────────────────

    public function joinCount(): int
    {
        return count($this->joins);
    }

    public function isEloquent(): bool
    {
        return $this->rootType === 'eloquent';
    }

    public function isDbFacade(): bool
    {
        return $this->rootType === 'db';
    }

    public function hasSelectStar(): bool
    {
        if (empty($this->selects)) {
            return true; // 未指定欄位等同 SELECT *
        }

        foreach ($this->selects as $col) {
            if (str_ends_with(trim($col), '*')) {
                return true;
            }
        }

        return false;
    }

    public function locationString(): string
    {
        $base = "{$this->filePath}:{$this->startLine}";

        if ($this->className && $this->methodName) {
            return "{$this->className}::{$this->methodName}() @ {$base}";
        }

        return $base;
    }

    /**
     * 產生人讀的方法鏈摘要。
     */
    public function chainSummary(): string
    {
        $root = "{$this->rootType}::{$this->rootMethod}("
              . implode(', ', array_map(fn($a) => $this->stringifyArg($a), $this->rootArgs))
              . ')';

        $methods = array_column($this->chain, 'method');

        return $root . '->' . implode('->', $methods);
    }

    private function stringifyArg(mixed $arg): string
    {
        if (is_string($arg)) {
            return "'{$arg}'";
        }
        if (is_array($arg)) {
            return '[' . implode(', ', $arg) . ']';
        }
        if ($arg === null) {
            return 'null';
        }

        return (string) $arg;
    }
}
