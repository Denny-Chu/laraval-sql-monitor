<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL Monitor - @yield('title', 'Dashboard')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        [x-cloak] { display: none !important; }
        .severity-low      { @apply text-gray-500; }
        .severity-info     { @apply text-blue-500; }
        .severity-warning  { @apply text-yellow-500; }
        .severity-critical { @apply text-red-500; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
    {{-- 頂部導覽列 --}}
    <nav class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
            </svg>
            <h1 class="text-lg font-semibold text-gray-800">Laravel SQL Monitor</h1>
        </div>
        <div class="flex items-center space-x-4 text-sm text-gray-500">
            <span>Environment: <strong class="text-green-600">{{ app()->environment() }}</strong></span>
            <span>|</span>
            <span id="live-indicator" class="flex items-center">
                <span class="w-2 h-2 bg-green-400 rounded-full mr-1 animate-pulse"></span>
                Live
            </span>
        </div>
    </nav>

    {{-- 主要內容 --}}
    <main class="max-w-7xl mx-auto px-4 py-6">
        @yield('content')
    </main>

    @yield('scripts')
</body>
</html>
