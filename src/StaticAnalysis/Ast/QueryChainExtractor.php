<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Arg;

/**
 * 從 PhpParser 的 AST 節點中提取查詢方法鏈。
 *
 * 輸入：任意一個 AST 節點（可能是 MethodCall 的最外層）
 * 輸出：QueryCallSite 或 null
 *
 * 識別以下根節點：
 *   DB::table('...')   → rootType = 'db',      rootMethod = 'table'
 *   DB::select('...')  → rootType = 'db',      rootMethod = 'select'
 *   User::query()      → rootType = 'eloquent', rootMethod = 'query'
 *   User::where(...)   → rootType = 'eloquent', rootMethod = 'where'
 *   User::with(...)    → rootType = 'eloquent', rootMethod = 'with'
 *   User::all()        → rootType = 'eloquent', rootMethod = 'all'
 */
class QueryChainExtractor
{
    /** DB Facade 靜態方法（作為鏈式查詢起點） */
    private const DB_ROOT_METHODS = [
        'table', 'select', 'raw', 'query', 'statement',
        'transaction', 'unprepared',
    ];

    /** Eloquent/Model 靜態方法（作為鏈式查詢起點） */
    private const ELOQUENT_ROOT_METHODS = [
        'query', 'all', 'find', 'findOrFail', 'findMany',
        'first', 'firstOrFail', 'firstOrCreate', 'firstOrNew',
        'where', 'whereIn', 'whereNotIn', 'whereBetween', 'whereNull', 'whereNotNull',
        'with', 'without', 'withCount', 'withSum', 'withAvg', 'withMin', 'withMax',
        'has', 'whereHas', 'doesntHave', 'whereDoesntHave',
        'orderBy', 'orderByDesc', 'orderByRaw',
        'groupBy', 'having',
        'join', 'leftJoin', 'rightJoin', 'crossJoin',
        'select', 'addSelect', 'selectRaw',
        'limit', 'take', 'skip', 'offset',
        'paginate', 'simplePaginate', 'cursorPaginate',
        'count', 'sum', 'avg', 'min', 'max',
        'create', 'insert', 'update', 'delete', 'truncate',
        'when', 'unless',
        'union', 'unionAll',
    ];

    /** 終止方法（代表查詢最終執行） */
    private const TERMINAL_METHODS = [
        'get', 'first', 'firstOrFail', 'find', 'findOrFail',
        'paginate', 'simplePaginate', 'cursorPaginate',
        'count', 'exists', 'doesntExist',
        'sum', 'avg', 'min', 'max',
        'value', 'pluck', 'lists',
        'chunk', 'each', 'lazy', 'cursor',
        'insert', 'insertOrIgnore', 'insertGetId', 'upsert',
        'update', 'delete', 'truncate', 'forceDelete',
        'all', 'toSql', 'dd', 'dump',
    ];

    /** JOIN 方法 */
    private const JOIN_METHODS = [
        'join', 'leftJoin', 'rightJoin', 'crossJoin',
        'joinSub', 'leftJoinSub', 'rightJoinSub',
    ];

    /** WHERE 方法 */
    private const WHERE_METHODS = [
        'where', 'orWhere', 'whereIn', 'whereNotIn',
        'whereBetween', 'whereNull', 'whereNotNull',
        'whereRaw', 'orWhereRaw',
        'whereLike', 'whereDate', 'whereYear', 'whereMonth',
        'whereColumn', 'whereExists', 'whereDoesntExist',
        'whereHas', 'whereDoesntHave',
        'when', 'unless',
    ];

    /** WITH (Eager Load) 方法 */
    private const WITH_METHODS = [
        'with', 'withCount', 'withSum', 'withAvg', 'withMin', 'withMax',
        'without', 'load',
    ];

    // ─── 公開介面 ─────────────────────────────────────────

    /**
     * 嘗試從節點提取查詢鏈。
     * 若節點不是查詢鏈的一部分，回傳 null。
     */
    public function extract(Node $node, string $filePath): ?QueryCallSite
    {
        // 必須是 MethodCall 或 StaticCall
        if (! ($node instanceof MethodCall || $node instanceof StaticCall)) {
            return null;
        }

        // 展開方法鏈，由外向內收集所有方法
        $calls = $this->flattenChain($node);

        if (empty($calls)) {
            return null;
        }

        // 最底層必須是 StaticCall（DB:: 或 Model::）
        $root = end($calls);

        if (! $root instanceof StaticCall) {
            return null;
        }

        $rootClass  = $this->resolveClassName($root->class);
        $rootMethod = $root->name instanceof Identifier ? $root->name->toString() : null;

        if ($rootClass === null || $rootMethod === null) {
            return null;
        }

        // 判斷根類型
        $rootType = $this->resolveRootType($rootClass, $rootMethod);

        if ($rootType === null) {
            return null;
        }

        // 建立 CallSite
        $callSite              = new QueryCallSite();
        $callSite->rootType    = $rootType;
        $callSite->rootMethod  = $rootMethod;
        $callSite->rootArgs    = $this->extractArgs($root->args);
        $callSite->filePath    = $filePath;
        $callSite->startLine   = $root->getStartLine();
        $callSite->endLine     = $node->getEndLine();

        // 反轉：從內到外，依方法鏈順序處理
        $chainNodes = array_reverse(array_slice($calls, 0, -1));

        foreach ($chainNodes as $callNode) {
            if (! $callNode instanceof MethodCall) {
                continue;
            }

            $method = $callNode->name instanceof Identifier
                ? $callNode->name->toString()
                : null;

            if ($method === null) {
                continue;
            }

            $args = $this->extractArgs($callNode->args);

            // 記錄到通用鏈
            $callSite->chain[] = [
                'method' => $method,
                'args'   => $args,
                'line'   => $callNode->getStartLine(),
            ];

            // 分類記錄
            $this->classifyCall($callSite, $method, $args, $callNode->getStartLine());
        }

        // 最外層方法通常是終止方法
        if ($node instanceof MethodCall) {
            $outerMethod = $node->name instanceof Identifier ? $node->name->toString() : null;

            if ($outerMethod && in_array($outerMethod, self::TERMINAL_METHODS, true)) {
                $callSite->terminalMethod = $outerMethod;
            }
        }

        return $callSite;
    }

    // ─── 鏈展開 ──────────────────────────────────────────

    /**
     * 將巢狀的 MethodCall/StaticCall 鏈展開為平坦的陣列，
     * 最外層在 index 0，最底層（root）在最後。
     */
    private function flattenChain(Node $node): array
    {
        $calls = [];

        $current = $node;

        while (true) {
            if ($current instanceof MethodCall) {
                $calls[] = $current;
                $current = $current->var;
            } elseif ($current instanceof StaticCall) {
                $calls[] = $current;
                break;
            } elseif ($current instanceof Variable) {
                // 鏈從一個變數開始（非 DB:: 或 Model::），跳過
                break;
            } else {
                break;
            }
        }

        return $calls;
    }

    // ─── 分類個別方法 ─────────────────────────────────────

    private function classifyCall(QueryCallSite $site, string $method, array $args, int $line): void
    {
        if (in_array($method, self::JOIN_METHODS, true)) {
            $site->joins[] = [
                'type'      => $this->resolveJoinType($method),
                'table'     => $args[0] ?? null,
                'condition' => $this->stringifyJoinCondition($args),
                'line'      => $line,
            ];
        }

        if (in_array($method, self::WHERE_METHODS, true)) {
            $site->wheres[] = [
                'method'   => $method,
                'column'   => $args[0] ?? null,
                'operator' => $this->resolveOperator($args),
                'value'    => $this->resolveWhereValue($args),
                'line'     => $line,
            ];
        }

        if (in_array($method, self::WITH_METHODS, true)) {
            foreach ($args as $arg) {
                if (is_string($arg)) {
                    $site->withs[] = $arg;
                } elseif (is_array($arg)) {
                    $site->withs = array_merge($site->withs, array_keys($arg));
                }
            }
        }

        if (in_array($method, ['select', 'addSelect', 'selectRaw'], true)) {
            foreach ($args as $arg) {
                if (is_string($arg)) {
                    $site->selects[] = $arg;
                } elseif (is_array($arg)) {
                    $site->selects = array_merge($site->selects, $arg);
                }
            }
        }

        if (in_array($method, ['orderBy', 'orderByDesc', 'orderByRaw', 'latest', 'oldest'], true)) {
            $site->hasOrderBy = true;
        }

        if (in_array($method, ['groupBy', 'having', 'havingRaw'], true)) {
            $site->hasGroupBy = true;
        }

        if (in_array($method, ['limit', 'take', 'paginate', 'simplePaginate', 'cursorPaginate'], true)) {
            $site->hasLimit = true;
        }

        if (in_array($method, ['union', 'unionAll'], true)) {
            $site->hasUnion = true;
        }
    }

    // ─── 輔助方法 ─────────────────────────────────────────

    private function resolveRootType(string $class, string $method): ?string
    {
        // DB Facade
        if (in_array($class, ['DB', 'Illuminate\\Support\\Facades\\DB'], true)) {
            if (in_array($method, self::DB_ROOT_METHODS, true)) {
                return 'db';
            }
        }

        // Eloquent Model（任何不是 DB 的類，且呼叫了 Eloquent 靜態方法）
        if ($class !== 'DB' && in_array($method, self::ELOQUENT_ROOT_METHODS, true)) {
            return 'eloquent';
        }

        return null;
    }

    private function resolveClassName(mixed $class): ?string
    {
        if ($class instanceof Name) {
            return $class->toString();
        }
        if (is_string($class)) {
            return $class;
        }

        return null;
    }

    /**
     * 提取 Arg 節點陣列為純 PHP 值。
     */
    private function extractArgs(array $args): array
    {
        $result = [];

        foreach ($args as $arg) {
            if ($arg instanceof Arg) {
                $result[] = $this->extractValue($arg->value);
            }
        }

        return $result;
    }

    private function extractValue(Node $node): mixed
    {
        return match (true) {
            $node instanceof String_
                => $node->value,

            $node instanceof Node\Scalar\LNumber
                => $node->value,

            $node instanceof Node\Scalar\DNumber
                => $node->value,

            $node instanceof Node\Expr\ConstFetch
                => strtolower($node->name->toString()),

            $node instanceof Node\Expr\Array_
                => $this->extractArray($node),

            $node instanceof Node\Expr\Closure
                => 'Closure',

            $node instanceof Node\Expr\ArrowFunction
                => 'Closure',

            $node instanceof Node\Expr\Variable
                => '$' . (is_string($node->name) ? $node->name : '?'),

            $node instanceof Node\Expr\ClassConstFetch
                => $this->resolveClassName($node->class) . '::' . ($node->name instanceof Identifier ? $node->name->toString() : '?'),

            default => null,
        };
    }

    private function extractArray(Node\Expr\Array_ $node): array
    {
        $result = [];

        foreach ($node->items as $item) {
            if ($item === null) {
                continue;
            }

            $key   = $item->key !== null ? $this->extractValue($item->key) : null;
            $value = $this->extractValue($item->value);

            if ($key !== null) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function resolveJoinType(string $method): string
    {
        return match ($method) {
            'leftJoin', 'leftJoinSub'   => 'LEFT JOIN',
            'rightJoin', 'rightJoinSub' => 'RIGHT JOIN',
            'crossJoin'                 => 'CROSS JOIN',
            default                     => 'INNER JOIN',
        };
    }

    private function resolveOperator(array $args): string
    {
        // where(col, val) → 隱含 =
        // where(col, op, val) → 明確 operator
        if (count($args) >= 3 && is_string($args[1])) {
            return $args[1];
        }

        return '=';
    }

    private function resolveWhereValue(array $args): mixed
    {
        if (count($args) >= 3) {
            return $args[2];
        }

        return $args[1] ?? null;
    }

    private function stringifyJoinCondition(array $args): string
    {
        if (count($args) >= 4) {
            return "{$args[1]} {$args[2]} {$args[3]}";
        }

        return '';
    }
}
