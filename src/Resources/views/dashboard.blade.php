@extends('sql-monitor::layouts.monitor-layout')

@section('title', 'Dashboard')

@section('content')

{{-- 摘要卡片 --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6" id="summary-cards">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Total Queries (DB)</p>
        <p class="text-2xl font-bold text-gray-900" id="stat-total">{{ $metrics['stats']['total'] ?? 0 }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Slow Queries</p>
        <p class="text-2xl font-bold text-red-600" id="stat-slow">{{ $metrics['stats']['slow_queries'] ?? 0 }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">N+1 Patterns</p>
        <p class="text-2xl font-bold text-yellow-600" id="stat-n1">{{ $metrics['stats']['n1_queries'] ?? 0 }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-sm text-gray-500">Duplicate Queries</p>
        <p class="text-2xl font-bold text-blue-600" id="stat-dup">{{ $metrics['stats']['duplicate_queries'] ?? 0 }}</p>
    </div>
</div>

{{-- 查詢列表 --}}
<div class="bg-white rounded-lg shadow">
    <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-lg font-semibold text-gray-800">Query Log</h2>
        <div class="flex items-center space-x-3">
            <div class="flex space-x-2 text-sm">
                <button onclick="filterQueries('all')"   class="filter-btn px-3 py-1 rounded bg-gray-200 hover:bg-gray-300" data-filter="all">All</button>
                <button onclick="filterQueries('slow')"  class="filter-btn px-3 py-1 rounded bg-red-100 hover:bg-red-200 text-red-700" data-filter="slow">Slow</button>
                <button onclick="filterQueries('n1')"    class="filter-btn px-3 py-1 rounded bg-yellow-100 hover:bg-yellow-200 text-yellow-700" data-filter="n1">N+1</button>
                <button onclick="filterQueries('dup')"   class="filter-btn px-3 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700" data-filter="dup">Duplicate</button>
                <button onclick="filterQueries('memory')" class="filter-btn px-3 py-1 rounded bg-green-100 hover:bg-green-200 text-green-700" data-filter="memory">Recent</button>
            </div>
            <span class="text-xs text-gray-400" id="poll-status">Polling every {{ config('sql-monitor.dashboard.polling_interval', 5) }}s</span>
        </div>
    </div>

    <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto" id="query-list">
        <div class="px-4 py-8 text-center text-gray-400" id="empty-state">
            Loading queries...
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
(function() {
    const POLL_URL      = '{{ route("sql-monitor.api.poll") }}';
    const POLL_INTERVAL = {{ config('sql-monitor.dashboard.polling_interval', 5) }} * 1000;

    let currentFilter = 'all';
    let pollTimer     = null;

    // ─── Polling ─────────────────────────────────────────
    async function poll() {
        try {
            const res  = await fetch(POLL_URL);
            const data = await res.json();
            updateStats(data.stats || {});
            updateQueryList(data.db_queries || [], data.memory_queries || []);
            document.getElementById('poll-status').textContent =
                'Updated ' + new Date().toLocaleTimeString();
        } catch (e) {
            document.getElementById('poll-status').textContent = 'Poll failed';
        }
    }

    // ─── Stats ───────────────────────────────────────────
    function updateStats(stats) {
        setText('stat-total', stats.total ?? 0);
        setText('stat-slow',  stats.slow_queries ?? 0);
        setText('stat-n1',    stats.n1_queries ?? 0);
        setText('stat-dup',   stats.duplicate_queries ?? 0);
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    // ─── Query List ──────────────────────────────────────
    function updateQueryList(dbQueries, memoryQueries) {
        const container = document.getElementById('query-list');

        // 合併並標記來源
        const all = [];
        dbQueries.forEach(q => { q._source = 'db'; all.push(q); });
        memoryQueries.forEach(q => { q._source = 'memory'; all.push(q); });

        // 排序：最新在最前
        all.sort((a, b) => (b.timestamp || 0) - (a.timestamp || 0));

        // 過濾
        const filtered = filterData(all, currentFilter);

        if (filtered.length === 0) {
            container.innerHTML =
                '<div class="px-4 py-8 text-center text-gray-400">' +
                'No queries captured yet. Make a request to start monitoring.' +
                '</div>';
            return;
        }

        container.innerHTML = filtered.map(renderQuery).join('');
    }

    function filterData(queries, filter) {
        switch (filter) {
            case 'slow':   return queries.filter(q => q.is_slow);
            case 'n1':     return queries.filter(q => q.is_n1);
            case 'dup':    return queries.filter(q => q.is_duplicate);
            case 'memory': return queries.filter(q => q._source === 'memory');
            default:       return queries;
        }
    }

    function renderQuery(q) {
        const timeMs  = parseFloat(q.execution_time_ms || 0).toFixed(2);
        const timeClass = timeMs > 100 ? 'text-red-600' : timeMs > 50 ? 'text-yellow-600' : 'text-green-600';

        let badges = '';
        if (q.is_slow)      badges += badge('SLOW', 'bg-red-100 text-red-700');
        if (q.is_n1)        badges += badge('N+1 (' + (q.n1_count || 0) + 'x)', 'bg-yellow-100 text-yellow-700');
        if (q.is_duplicate) badges += badge('DUP (' + (q.duplicate_count || 0) + 'x)', 'bg-blue-100 text-blue-700');
        if (q._source === 'memory') badges += badge('RECENT', 'bg-green-100 text-green-700');

        const severity = q.complexity_severity || (q.complexity && q.complexity.severity) || '';
        if (severity === 'warning')  badges += badge('WARNING', 'bg-orange-100 text-orange-700');
        if (severity === 'critical') badges += badge('CRITICAL', 'bg-red-100 text-red-800');

        return '<div class="px-4 py-3 hover:bg-gray-50 transition-colors">' +
            '<div class="flex justify-between items-start">' +
                '<div class="flex-1 mr-4">' +
                    '<code class="text-sm text-gray-700 break-all">' + escapeHtml(q.sql || '') + '</code>' +
                    (badges ? '<div class="mt-1">' + badges + '</div>' : '') +
                    (q.n1_suggestion ? '<p class="text-xs text-gray-500 mt-1">' + escapeHtml(q.n1_suggestion) + '</p>' : '') +
                '</div>' +
                '<div class="text-right whitespace-nowrap">' +
                    '<span class="text-sm font-mono ' + timeClass + '">' + timeMs + 'ms</span>' +
                    '<p class="text-xs text-gray-400">' + escapeHtml(q.connection || q.connection_name || '') + '</p>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function badge(text, classes) {
        return '<span class="inline-block text-xs px-2 py-0.5 rounded mr-1 ' + classes + '">' + text + '</span>';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ─── Filter ──────────────────────────────────────────
    window.filterQueries = function(type) {
        currentFilter = type;
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.toggle('ring-2', btn.dataset.filter === type);
            btn.classList.toggle('ring-offset-1', btn.dataset.filter === type);
        });
        poll();
    };

    // ─── Init ────────────────────────────────────────────
    poll();
    pollTimer = setInterval(poll, POLL_INTERVAL);
})();
</script>
@endsection
