@extends('sql-monitor::layouts.monitor-layout')

@section('title', 'Dashboard')

@section('content')

{{-- 摘要卡片 --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Total Queries</p>
        <p class="text-2xl font-bold text-gray-900">{{ $metrics['summary']['total_queries'] ?? 0 }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Total Time</p>
        <p class="text-2xl font-bold text-gray-900">{{ $metrics['summary']['total_time_ms'] ?? 0 }} ms</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Slow Queries</p>
        <p class="text-2xl font-bold text-red-600">{{ $metrics['summary']['slow_query_count'] ?? 0 }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">N+1 Patterns</p>
        <p class="text-2xl font-bold text-yellow-600">{{ count($metrics['n1_patterns'] ?? []) }}</p>
    </div>
</div>

{{-- N+1 警告面板 --}}
@if (!empty($metrics['n1_patterns']))
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
    <h2 class="text-lg font-semibold text-yellow-800 mb-3">N+1 Query Patterns Detected</h2>
    @foreach ($metrics['n1_patterns'] as $pattern)
    <div class="bg-white rounded p-3 mb-2 border-l-4 border-yellow-400">
        <div class="flex justify-between items-center mb-1">
            <span class="text-sm font-mono text-gray-700 truncate">{{ $pattern['normalized_sql'] ?? '' }}</span>
            <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded">
                {{ $pattern['count'] ?? 0 }}x executed
            </span>
        </div>
        <p class="text-xs text-gray-500">{{ $pattern['suggestion'] ?? '' }}</p>
    </div>
    @endforeach
</div>
@endif

{{-- 重複查詢面板 --}}
@if (!empty($metrics['duplicates']))
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
    <h2 class="text-lg font-semibold text-blue-800 mb-3">Duplicate Queries</h2>
    @foreach ($metrics['duplicates'] as $dup)
    <div class="bg-white rounded p-3 mb-2 border-l-4 border-blue-400">
        <div class="flex justify-between items-center">
            <span class="text-sm font-mono text-gray-700 truncate">{{ $dup['sql'] ?? '' }}</span>
            <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">
                {{ $dup['count'] ?? 0 }}x · Save {{ $dup['potential_saving'] ?? 0 }}ms
            </span>
        </div>
    </div>
    @endforeach
</div>
@endif

{{-- 查詢列表 --}}
<div class="bg-white rounded-lg shadow">
    <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-lg font-semibold text-gray-800">Query Log</h2>
        <div class="flex space-x-2 text-sm">
            <button onclick="filterQueries('all')"   class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200">All</button>
            <button onclick="filterQueries('slow')"  class="px-3 py-1 rounded bg-red-100 hover:bg-red-200 text-red-700">Slow</button>
            <button onclick="filterQueries('select')" class="px-3 py-1 rounded bg-green-100 hover:bg-green-200 text-green-700">SELECT</button>
        </div>
    </div>

    <div class="divide-y divide-gray-100 max-h-96 overflow-y-auto" id="query-list">
        @forelse ($metrics['queries'] ?? [] as $query)
        <div class="px-4 py-3 hover:bg-gray-50 transition-colors">
            <div class="flex justify-between items-start">
                <div class="flex-1 mr-4">
                    <code class="text-sm text-gray-700 break-all">{{ $query['sql'] ?? '' }}</code>
                    @if (!empty($query['complexity']['warnings']))
                        <div class="mt-1">
                            @foreach ($query['complexity']['warnings'] as $warning)
                                <span class="inline-block text-xs px-2 py-0.5 rounded
                                    @if ($warning['severity'] === 'critical') bg-red-100 text-red-700
                                    @elseif ($warning['severity'] === 'warning') bg-yellow-100 text-yellow-700
                                    @else bg-blue-100 text-blue-700 @endif">
                                    {{ $warning['message'] ?? '' }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="text-right whitespace-nowrap">
                    <span class="text-sm font-mono
                        @if (($query['execution_time_ms'] ?? 0) > 100) text-red-600
                        @elseif (($query['execution_time_ms'] ?? 0) > 50) text-yellow-600
                        @else text-green-600 @endif">
                        {{ $query['execution_time_ms'] ?? 0 }}ms
                    </span>
                </div>
            </div>
        </div>
        @empty
        <div class="px-4 py-8 text-center text-gray-400">
            No queries captured yet. Make a request to start monitoring.
        </div>
        @endforelse
    </div>
</div>

@endsection

@section('scripts')
<script>
    // 查詢過濾
    function filterQueries(type) {
        // TODO: 透過 Livewire 或 AJAX 實作即時過濾
        console.log('Filter:', type);
    }

    // 即時更新（透過 WebSocket）
    if (typeof Echo !== 'undefined') {
        Echo.channel('sql-monitor')
            .listen('.query.captured', (data) => {
                console.log('[SQL Monitor] Query captured:', data);
            })
            .listen('.query.slow', (data) => {
                console.warn('[SQL Monitor] Slow query!', data);
            })
            .listen('.query.n1_detected', (data) => {
                console.warn('[SQL Monitor] N+1 pattern!', data);
            });
    }
</script>
@endsection
