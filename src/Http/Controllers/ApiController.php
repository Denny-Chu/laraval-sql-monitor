<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelSqlMonitor\Monitor\MetricsCollector;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;

class ApiController extends Controller
{
    public function __construct(
        protected MetricsCollector     $metrics,
        protected ?QueryStoreInterface $store,
    ) {}

    /**
     * GET /sql-monitor/api/queries
     * 取得查詢列表。
     */
    public function queries(Request $request): JsonResponse
    {
        $filters = $request->only(['is_slow', 'query_type', 'min_time_ms', 'table']);
        $limit   = (int) $request->get('limit', 50);
        $offset  = (int) $request->get('offset', 0);

        $data = $this->store
            ? $this->store->query($filters, $limit, $offset)
            : [];

        return response()->json([
            'data'   => $data,
            'meta'   => ['limit' => $limit, 'offset' => $offset],
        ]);
    }

    /**
     * GET /sql-monitor/api/analytics
     * 取得分析統計數據。
     */
    public function analytics(): JsonResponse
    {
        return response()->json([
            'data' => $this->metrics->collect(),
        ]);
    }

    /**
     * GET /sql-monitor/api/slow-queries
     * 取得慢查詢列表。
     */
    public function slowQueries(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 50);

        $data = $this->store
            ? $this->store->slowQueries($limit)
            : [];

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * GET /sql-monitor/api/stats
     * 取得儲存層統計。
     */
    public function stats(): JsonResponse
    {
        $data = $this->store
            ? $this->store->stats()
            : [];

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * DELETE /sql-monitor/api/logs
     * 清理日誌。
     */
    public function cleanup(Request $request): JsonResponse
    {
        $hours   = (int) $request->get('older_than_hours', 24);
        $deleted = $this->store ? $this->store->cleanup($hours) : 0;

        return response()->json([
            'deleted' => $deleted,
        ]);
    }
}
