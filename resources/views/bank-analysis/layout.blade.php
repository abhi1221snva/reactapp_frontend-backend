<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Analysis Viewer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #f1f5f9; }
        .loader-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 9999;
            align-items: center; justify-content: center;
        }
        .loader-overlay.active { display: flex; }
        .spinner {
            width: 48px; height: 48px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        details summary { cursor: pointer; user-select: none; }
        details summary::-webkit-details-marker { display: none; }
        details summary::before {
            content: '▶'; display: inline-block; margin-right: 8px;
            transition: transform 0.2s; font-size: 0.75rem;
        }
        details[open] summary::before { transform: rotate(90deg); }
        .stat-card {
            background: white; border-radius: 0.75rem; padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        .section-panel {
            background: white; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            margin-bottom: 1rem; overflow: hidden;
        }
        .section-panel summary {
            padding: 1rem 1.25rem; font-weight: 600; font-size: 0.95rem;
            color: #1e293b; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .section-panel[open] summary { border-bottom: 1px solid #e2e8f0; }
        .section-body { padding: 1.25rem; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .data-table th {
            background: #f8fafc; padding: 0.625rem 0.75rem;
            text-align: left; font-weight: 600; color: #475569;
            border-bottom: 2px solid #e2e8f0; white-space: nowrap;
        }
        .data-table td {
            padding: 0.5rem 0.75rem; border-bottom: 1px solid #f1f5f9; color: #334155;
        }
        .data-table tr:hover td { background: #f8fafc; }
        .badge {
            display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px;
            font-size: 0.75rem; font-weight: 600;
        }
        .badge-green  { background: #dcfce7; color: #166534; }
        .badge-red    { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef9c3; color: #854d0e; }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-gray   { background: #f1f5f9; color: #475569; }
        .timeline-item {
            position: relative; padding-left: 1.75rem; padding-bottom: 1rem;
            border-left: 2px solid #e2e8f0;
        }
        .timeline-item::before {
            content: ''; position: absolute; left: -5px; top: 4px;
            width: 8px; height: 8px; border-radius: 50%; background: #6366f1;
        }
        .timeline-item:last-child { border-left-color: transparent; }
    </style>
</head>
<body class="min-h-screen">

    {{-- Loader --}}
    <div class="loader-overlay" id="loader">
        <div class="text-center">
            <div class="spinner mx-auto mb-4"></div>
            <p class="text-white text-lg font-medium">Analyzing bank statement...</p>
        </div>
    </div>

    {{-- Navbar --}}
    <nav class="bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h1 class="text-lg font-bold text-slate-800">Bank Analysis Viewer</h1>
            </div>
            <span class="text-xs text-slate-400">Debug & Validation Tool</span>
        </div>
    </nav>

    {{-- Content --}}
    <main class="max-w-7xl mx-auto px-4 py-6">
        @yield('content')
    </main>

    <script src="/js/bank-analysis-viewer.js"></script>
</body>
</html>
