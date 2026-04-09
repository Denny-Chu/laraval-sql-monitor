<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

/**
 * 中間件 — 在請求開始時檢查 Query Builder 的潛在問題。
 * （需要在應用代碼中調用 static analysis）
 */
class StaticAnalysisMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // 初始化靜態分析監聽器
        $this->setupStaticAnalysisListeners();

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    protected function setupStaticAnalysisListeners(): void
    {
        // TODO: 可在此處註冊全局的 Query Builder 監聽器
        // 例如：使用 PHP 的動態代理或 Doctrine Proxy 在執行前檢查
    }
}
