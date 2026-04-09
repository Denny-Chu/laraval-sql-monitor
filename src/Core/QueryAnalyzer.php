<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Core;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\JoinKeyword;
use Throwable;

/**
 * SQL 語句解析器 — 將 phpmyadmin/sql-parser 包裝為我們需要的 QueryAnalysis。
 *
 * 用法：
 *   $analyzer = new QueryAnalyzer();
 *   $analysis = $analyzer->analyze('SELECT * FROM users WHERE id = ?', [1]);
 */
class QueryAnalyzer
{
    /**
     * 解析並分析一條 SQL 語句。
     */
    public function analyze(string $sql, array $bindings = []): QueryAnalysis
    {
        try {
            $parser = new Parser($sql);

            if (empty($parser->statements)) {
                return QueryAnalysis::failed($sql, $bindings, 'No statements found');
            }

            $statement = $parser->statements[0];

            return new QueryAnalysis(
                sql: $sql,
                bindings: $bindings,
                queryType: $this->resolveQueryType($statement),
                selectColumns: $this->extractSelectColumns($statement),
                hasSelectStar: $this->detectSelectStar($statement),
                joins: $this->extractJoins($statement),
                subqueries: $this->extractSubqueries($statement),
                tables: $this->extractTables($statement),
                conditions: $this->extractConditions($statement),
                hasGroupBy: $this->hasClause($statement, 'group'),
                hasHaving: $this->hasClause($statement, 'having'),
                hasOrderBy: $this->hasClause($statement, 'order'),
                hasLimit: $this->hasClause($statement, 'limit'),
                hasUnion: $this->detectUnion($parser),
            );
        } catch (Throwable $e) {
            return QueryAnalysis::failed($sql, $bindings, $e->getMessage());
        }
    }

    // ─── 查詢類型 ─────────────────────────────────────────────

    private function resolveQueryType(Statement $stmt): string
    {
        return match (true) {
            $stmt instanceof SelectStatement => 'select',
            $stmt instanceof InsertStatement => 'insert',
            $stmt instanceof UpdateStatement => 'update',
            $stmt instanceof DeleteStatement => 'delete',
            default                          => 'other',
        };
    }

    // ─── SELECT 欄位提取 ──────────────────────────────────────

    private function extractSelectColumns(Statement $stmt): array
    {
        if (! $stmt instanceof SelectStatement || empty($stmt->expr)) {
            return [];
        }

        $columns = [];
        foreach ($stmt->expr as $expr) {
            if ($expr instanceof Expression) {
                $columns[] = [
                    'column'   => $expr->column,
                    'table'    => $expr->table,
                    'alias'    => $expr->alias,
                    'function' => $expr->function,
                    'expr'     => $expr->expr,
                ];
            }
        }

        return $columns;
    }

    // ─── SELECT * 偵測 ────────────────────────────────────────

    private function detectSelectStar(Statement $stmt): bool
    {
        if (! $stmt instanceof SelectStatement || empty($stmt->expr)) {
            return false;
        }

        foreach ($stmt->expr as $expr) {
            if ($expr instanceof Expression && $expr->column === '*') {
                return true;
            }
        }

        return false;
    }

    // ─── JOIN 分析 ────────────────────────────────────────────

    private function extractJoins(Statement $stmt): array
    {
        if (! $stmt instanceof SelectStatement || empty($stmt->join)) {
            return [];
        }

        $joins = [];
        foreach ($stmt->join as $join) {
            if ($join instanceof JoinKeyword) {
                $joinInfo = [
                    'type'  => $join->type ?? 'JOIN',
                    'table' => $join->expr?->table ?? $join->expr?->expr ?? 'unknown',
                    'alias' => $join->expr?->alias,
                ];

                // 提取 ON 條件
                if (! empty($join->on)) {
                    $onParts = [];
                    foreach ($join->on as $cond) {
                        $onParts[] = $cond->expr ?? '';
                    }
                    $joinInfo['on'] = implode(' AND ', array_filter($onParts));
                }

                $joins[] = $joinInfo;
            }
        }

        return $joins;
    }

    // ─── 子查詢偵測 ──────────────────────────────────────────

    private function extractSubqueries(Statement $stmt, int $depth = 0): array
    {
        $subqueries = [];

        if ($stmt instanceof SelectStatement) {
            // 檢查 FROM 中的子查詢
            if (! empty($stmt->from)) {
                foreach ($stmt->from as $from) {
                    if ($from instanceof Expression && $from->subquery !== null) {
                        $subqueries[] = [
                            'sql'      => $from->expr,
                            'depth'    => $depth + 1,
                            'location' => 'FROM',
                        ];
                    }
                }
            }

            // 檢查 WHERE 中的子查詢
            if (! empty($stmt->where)) {
                foreach ($stmt->where as $where) {
                    $expr = $where->expr ?? '';
                    if (stripos($expr, 'SELECT') !== false) {
                        $subqueries[] = [
                            'sql'      => $expr,
                            'depth'    => $depth + 1,
                            'location' => 'WHERE',
                        ];
                    }
                }
            }
        }

        return $subqueries;
    }

    // ─── 表名提取 ────────────────────────────────────────────

    private function extractTables(Statement $stmt): array
    {
        $tables = [];

        if ($stmt instanceof SelectStatement && ! empty($stmt->from)) {
            foreach ($stmt->from as $from) {
                if ($from instanceof Expression && $from->table !== null) {
                    $tables[] = $from->table;
                }
            }
        } elseif ($stmt instanceof UpdateStatement && ! empty($stmt->tables)) {
            foreach ($stmt->tables as $table) {
                if ($table instanceof Expression && $table->table !== null) {
                    $tables[] = $table->table;
                }
            }
        } elseif ($stmt instanceof InsertStatement && $stmt->into?->dest instanceof Expression) {
            $tables[] = $stmt->into->dest->table;
        } elseif ($stmt instanceof DeleteStatement && ! empty($stmt->from)) {
            foreach ($stmt->from as $from) {
                if ($from instanceof Expression && $from->table !== null) {
                    $tables[] = $from->table;
                }
            }
        }

        return array_unique(array_filter($tables));
    }

    // ─── WHERE 條件提取 ──────────────────────────────────────

    private function extractConditions(Statement $stmt): array
    {
        if (! property_exists($stmt, 'where') || empty($stmt->where)) {
            return [];
        }

        $conditions = [];
        foreach ($stmt->where as $cond) {
            $conditions[] = $cond->expr ?? '';
        }

        return array_filter($conditions);
    }

    // ─── 子句偵測 ────────────────────────────────────────────

    private function hasClause(Statement $stmt, string $clause): bool
    {
        return match ($clause) {
            'group'  => $stmt instanceof SelectStatement && ! empty($stmt->group),
            'having' => $stmt instanceof SelectStatement && ! empty($stmt->having),
            'order'  => $stmt instanceof SelectStatement && ! empty($stmt->order),
            'limit'  => $stmt instanceof SelectStatement && $stmt->limit !== null,
            default  => false,
        };
    }

    // ─── UNION 偵測 ──────────────────────────────────────────

    private function detectUnion(Parser $parser): bool
    {
        return count($parser->statements) > 1;
    }
}
