@extends('bank-analysis.layout')

@section('content')

{{-- Top Bar --}}
<div class="flex items-center justify-between mb-6">
    <a href="/bank-analysis-viewer" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to Input
    </a>
    <div class="flex items-center gap-3">
        <span class="text-xs text-slate-400">Session{{ count($sessions) > 1 ? 's' : '' }}: {{ implode(', ', array_map(fn($s) => substr($s, 0, 8) . '...', $sessions)) }}</span>
        <button onclick="toggleRawJson()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-semibold px-3 py-1.5 rounded-lg transition" id="rawJsonBtn">
            Show Raw JSON
        </button>
    </div>
</div>

{{-- Raw JSON (hidden by default) --}}
<div id="rawJsonBlock" class="hidden mb-6">
    <div class="section-panel">
        <details open>
            <summary>Raw API Response</summary>
            <div class="section-body">
                <pre class="bg-slate-900 text-green-400 text-xs p-4 rounded-lg overflow-x-auto max-h-[600px] overflow-y-auto leading-relaxed">{{ $rawJson }}</pre>
            </div>
        </details>
    </div>
</div>

{{-- ============================================================ --}}
{{-- A. SUMMARY SECTION --}}
{{-- ============================================================ --}}
@if(isset($data['data']['summary']) || isset($data['summary']))
@php $summary = $data['data']['summary'] ?? $data['summary'] ?? []; @endphp
<div class="mb-6">
    <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-3">Summary</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="stat-card">
            <p class="text-xs text-slate-500 font-medium">Total Transactions</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">{{ number_format($summary['total_transactions'] ?? 0) }}</p>
        </div>
        <div class="stat-card">
            <p class="text-xs text-slate-500 font-medium">Credits</p>
            <p class="text-2xl font-bold text-green-600 mt-1">${{ number_format($summary['total_credits'] ?? $summary['credit_amount'] ?? 0, 2) }}</p>
            @if(isset($summary['credit_count']))
            <p class="text-xs text-slate-400 mt-0.5">{{ $summary['credit_count'] }} transactions</p>
            @endif
        </div>
        <div class="stat-card">
            <p class="text-xs text-slate-500 font-medium">Debits</p>
            <p class="text-2xl font-bold text-red-600 mt-1">${{ number_format($summary['total_debits'] ?? $summary['debit_amount'] ?? 0, 2) }}</p>
            @if(isset($summary['debit_count']))
            <p class="text-xs text-slate-400 mt-0.5">{{ $summary['debit_count'] }} transactions</p>
            @endif
        </div>
        <div class="stat-card">
            <p class="text-xs text-slate-500 font-medium">Net Balance</p>
            @php $net = $summary['net_balance'] ?? ($summary['total_credits'] ?? 0) - ($summary['total_debits'] ?? 0); @endphp
            <p class="text-2xl font-bold {{ $net >= 0 ? 'text-green-600' : 'text-red-600' }} mt-1">${{ number_format($net, 2) }}</p>
        </div>
    </div>

    {{-- Extra summary cards if available --}}
    @if(isset($summary['returned_count']) || isset($summary['nsf_count']))
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
        @if(isset($summary['returned_count']))
        <div class="stat-card">
            <p class="text-xs text-slate-500 font-medium">Returned</p>
            <p class="text-xl font-bold text-amber-600 mt-1">{{ $summary['returned_count'] }}</p>
        </div>
        @endif
        @if(isset($summary['nsf_count']))
        <div class="stat-card">
            <p class="text-xs text-slate-500 font-medium">NSF Count</p>
            <p class="text-xl font-bold text-red-500 mt-1">{{ $summary['nsf_count'] }}</p>
        </div>
        @endif
        @if(isset($summary['date_range']))
        <div class="stat-card col-span-2">
            <p class="text-xs text-slate-500 font-medium">Date Range</p>
            <p class="text-base font-semibold text-slate-700 mt-1">
                {{ $summary['date_range']['start'] ?? '' }} &mdash; {{ $summary['date_range']['end'] ?? '' }}
            </p>
        </div>
        @endif
    </div>
    @endif
</div>
@endif

{{-- ============================================================ --}}
{{-- B. BALANCES SECTION --}}
{{-- ============================================================ --}}
@if(isset($data['data']['balances']) || isset($data['balances']))
@php $balances = $data['data']['balances'] ?? $data['balances'] ?? []; @endphp
<details class="section-panel" open>
    <summary>Balance Details</summary>
    <div class="section-body">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-slate-500 font-medium">Beginning Balance</p>
                <p class="text-lg font-bold text-slate-800">${{ number_format($balances['beginning_balance'] ?? 0, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Ending Balance</p>
                <p class="text-lg font-bold text-slate-800">${{ number_format($balances['ending_balance'] ?? 0, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Average Daily Balance</p>
                <p class="text-lg font-bold text-indigo-600">${{ number_format($balances['average_daily_balance'] ?? 0, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Negative Balance Days</p>
                @php $negDays = $balances['negative_balance_days'] ?? $balances['negative_days'] ?? 0; @endphp
                <p class="text-lg font-bold {{ $negDays > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $negDays }}</p>
            </div>
        </div>

        @if(isset($balances['lowest_balance']))
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 pt-4 border-t border-slate-100">
            <div>
                <p class="text-xs text-slate-500 font-medium">Lowest Balance</p>
                <p class="text-base font-semibold text-red-600">${{ number_format($balances['lowest_balance'], 2) }}</p>
            </div>
            @if(isset($balances['highest_balance']))
            <div>
                <p class="text-xs text-slate-500 font-medium">Highest Balance</p>
                <p class="text-base font-semibold text-green-600">${{ number_format($balances['highest_balance'], 2) }}</p>
            </div>
            @endif
        </div>
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- C. RISK SECTION --}}
{{-- ============================================================ --}}
@if(isset($data['data']['risk']) || isset($data['risk']))
@php $risk = $data['data']['risk'] ?? $data['risk'] ?? []; @endphp
<details class="section-panel" open>
    <summary>Risk Assessment</summary>
    <div class="section-body">
        <div class="flex flex-wrap gap-6 items-start">
            {{-- Score circle --}}
            <div class="text-center">
                @php
                    $score = $risk['risk_score'] ?? $risk['score'] ?? 0;
                    $grade = $risk['risk_grade'] ?? $risk['grade'] ?? 'N/A';
                    $scoreColor = $score >= 70 ? 'text-green-600' : ($score >= 40 ? 'text-amber-600' : 'text-red-600');
                @endphp
                <div class="w-24 h-24 rounded-full border-4 {{ $score >= 70 ? 'border-green-400' : ($score >= 40 ? 'border-amber-400' : 'border-red-400') }} flex items-center justify-center mx-auto">
                    <span class="text-2xl font-bold {{ $scoreColor }}">{{ $score }}</span>
                </div>
                <p class="text-sm font-semibold text-slate-600 mt-2">Score</p>
            </div>

            {{-- Grade --}}
            <div>
                <p class="text-xs text-slate-500 font-medium">Risk Grade</p>
                <span class="text-3xl font-bold {{ $scoreColor }}">{{ $grade }}</span>
            </div>

            {{-- Factors --}}
            @if(isset($risk['risk_factors']) || isset($risk['factors']))
            @php $factors = $risk['risk_factors'] ?? $risk['factors'] ?? []; @endphp
            <div class="flex-1 min-w-[200px]">
                <p class="text-xs text-slate-500 font-medium mb-2">Risk Factors</p>
                @if(count($factors) > 0)
                <ul class="space-y-1">
                    @foreach($factors as $factor)
                    <li class="flex items-start gap-2 text-sm text-slate-700">
                        <span class="text-red-400 mt-0.5">&#9679;</span>
                        @if(is_array($factor))
                            {{ $factor['description'] ?? $factor['factor'] ?? json_encode($factor) }}
                            @if(isset($factor['severity']))
                                <span class="badge {{ $factor['severity'] === 'high' ? 'badge-red' : ($factor['severity'] === 'medium' ? 'badge-yellow' : 'badge-gray') }}">{{ $factor['severity'] }}</span>
                            @endif
                        @else
                            {{ $factor }}
                        @endif
                    </li>
                    @endforeach
                </ul>
                @else
                <p class="text-sm text-slate-400 italic">No risk factors identified</p>
                @endif
            </div>
            @endif
        </div>
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- D. REVENUE SECTION --}}
{{-- ============================================================ --}}
@if(isset($data['data']['revenue']) || isset($data['revenue']))
@php $revenue = $data['data']['revenue'] ?? $data['revenue'] ?? []; @endphp
<details class="section-panel" open>
    <summary>Revenue</summary>
    <div class="section-body">
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div>
                <p class="text-xs text-slate-500 font-medium">True Revenue</p>
                <p class="text-xl font-bold text-green-600">${{ number_format($revenue['true_revenue'] ?? $revenue['total_revenue'] ?? 0, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Revenue Decline Alert</p>
                @php $decline = $revenue['revenue_decline_alert'] ?? $revenue['decline_alert'] ?? false; @endphp
                <span class="badge {{ $decline ? 'badge-red' : 'badge-green' }} text-sm mt-1">
                    {{ $decline ? 'Yes — Declining' : 'No' }}
                </span>
            </div>
            @if(isset($revenue['average_monthly_revenue']))
            <div>
                <p class="text-xs text-slate-500 font-medium">Avg Monthly Revenue</p>
                <p class="text-xl font-bold text-slate-700">${{ number_format($revenue['average_monthly_revenue'], 2) }}</p>
            </div>
            @endif
        </div>

        {{-- Monthly Velocity --}}
        @if(isset($revenue['monthly_velocity']) || isset($revenue['monthly_revenue']))
        @php $velocity = $revenue['monthly_velocity'] ?? $revenue['monthly_revenue'] ?? []; @endphp
        @if(count($velocity) > 0)
        <div class="mt-4 pt-4 border-t border-slate-100">
            <p class="text-xs text-slate-500 font-medium mb-2">Monthly Velocity</p>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Revenue</th>
                            @if(isset(collect($velocity)->first()['transaction_count']))
                            <th>Transactions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($velocity as $month => $val)
                        <tr>
                            <td class="font-medium">{{ is_array($val) ? ($val['month'] ?? $month) : $month }}</td>
                            <td class="text-green-600 font-semibold">${{ number_format(is_array($val) ? ($val['revenue'] ?? $val['amount'] ?? 0) : $val, 2) }}</td>
                            @if(is_array($val) && isset($val['transaction_count']))
                            <td>{{ $val['transaction_count'] }}</td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- E. MONTHLY DATA --}}
{{-- ============================================================ --}}
@if(isset($data['data']['monthly_data']) || isset($data['monthly_data']))
@php $monthly = $data['data']['monthly_data'] ?? $data['monthly_data'] ?? []; @endphp
<details class="section-panel">
    <summary>Monthly Breakdown</summary>
    <div class="section-body overflow-x-auto">
        @if(is_array($monthly) && count($monthly) > 0)
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Credits</th>
                    <th>Debits</th>
                    <th>Net</th>
                    <th>Transactions</th>
                    <th>Avg Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($monthly as $m)
                @if(is_array($m))
                <tr>
                    <td class="font-medium">{{ $m['month'] ?? $m['period'] ?? '—' }}</td>
                    <td class="text-green-600">${{ number_format($m['total_credits'] ?? $m['credits'] ?? 0, 2) }}</td>
                    <td class="text-red-600">${{ number_format($m['total_debits'] ?? $m['debits'] ?? 0, 2) }}</td>
                    <td class="font-semibold">${{ number_format(($m['total_credits'] ?? $m['credits'] ?? 0) - ($m['total_debits'] ?? $m['debits'] ?? 0), 2) }}</td>
                    <td>{{ $m['transaction_count'] ?? $m['total_transactions'] ?? '—' }}</td>
                    <td>${{ number_format($m['average_daily_balance'] ?? $m['avg_balance'] ?? 0, 2) }}</td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-sm text-slate-400 italic">No monthly data available</p>
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- F. MCA ANALYSIS --}}
{{-- ============================================================ --}}
@if(isset($data['data']['mca_analysis']) || isset($data['mca_analysis']))
@php $mca = $data['data']['mca_analysis'] ?? $data['mca_analysis'] ?? []; @endphp
<details class="section-panel" open>
    <summary>MCA Analysis</summary>
    <div class="section-body">
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
            <div>
                <p class="text-xs text-slate-500 font-medium">Total MCA Payments</p>
                <p class="text-xl font-bold text-red-600">${{ number_format($mca['total_mca_payments'] ?? $mca['total_payments'] ?? 0, 2) }}</p>
            </div>
            @if(isset($mca['mca_count']) || isset($mca['lender_count']))
            <div>
                <p class="text-xs text-slate-500 font-medium">MCA Lenders Detected</p>
                <p class="text-xl font-bold text-slate-700">{{ $mca['lender_count'] ?? $mca['mca_count'] ?? 0 }}</p>
            </div>
            @endif
            @if(isset($mca['estimated_monthly_mca']))
            <div>
                <p class="text-xs text-slate-500 font-medium">Est. Monthly MCA</p>
                <p class="text-xl font-bold text-amber-600">${{ number_format($mca['estimated_monthly_mca'], 2) }}</p>
            </div>
            @endif
        </div>

        @php $lenders = $mca['lenders'] ?? $mca['mca_lenders'] ?? []; @endphp
        @if(count($lenders) > 0)
        <div class="border-t border-slate-100 pt-4">
            <p class="text-xs text-slate-500 font-medium mb-2">Lenders</p>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Lender Name</th>
                        <th>Total Payments</th>
                        <th>Payment Count</th>
                        <th>Avg Payment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lenders as $lender)
                    <tr>
                        <td class="font-medium">
                            @if(is_array($lender))
                                {{ $lender['name'] ?? $lender['lender_name'] ?? '—' }}
                            @else
                                {{ $lender }}
                            @endif
                        </td>
                        <td class="text-red-600">${{ number_format(is_array($lender) ? ($lender['total_amount'] ?? $lender['total_payments'] ?? 0) : 0, 2) }}</td>
                        <td>{{ is_array($lender) ? ($lender['payment_count'] ?? $lender['count'] ?? '—') : '—' }}</td>
                        <td>${{ number_format(is_array($lender) ? ($lender['average_payment'] ?? $lender['avg_payment'] ?? 0) : 0, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- G. DEBT COLLECTOR SECTION --}}
{{-- ============================================================ --}}
@if(isset($data['data']['debt_collector_analysis']) || isset($data['debt_collector_analysis']))
@php $debt = $data['data']['debt_collector_analysis'] ?? $data['debt_collector_analysis'] ?? []; @endphp
<details class="section-panel">
    <summary>Debt Collectors</summary>
    <div class="section-body">
        @php $collectors = $debt['collectors'] ?? $debt['debt_collectors'] ?? (is_array($debt) && !isset($debt['collectors']) ? $debt : []); @endphp
        @if(is_array($collectors) && count($collectors) > 0)
        <table class="data-table">
            <thead>
                <tr>
                    <th>Collector Name</th>
                    <th>Total Amount</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                @foreach($collectors as $collector)
                <tr>
                    <td class="font-medium">
                        @if(is_array($collector))
                            {{ $collector['name'] ?? $collector['collector_name'] ?? '—' }}
                        @else
                            {{ $collector }}
                        @endif
                    </td>
                    <td class="text-red-600">${{ number_format(is_array($collector) ? ($collector['total_amount'] ?? 0) : 0, 2) }}</td>
                    <td>{{ is_array($collector) ? ($collector['count'] ?? $collector['transaction_count'] ?? '—') : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <p class="text-sm text-green-600 font-medium">No debt collector activity detected</p>
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- H. OFFER PREVIEW --}}
{{-- ============================================================ --}}
@if(isset($data['data']['offer_preview']) || isset($data['offer_preview']))
@php $offer = $data['data']['offer_preview'] ?? $data['offer_preview'] ?? []; @endphp
<details class="section-panel" open>
    <summary>Offer Preview</summary>
    <div class="section-body">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="stat-card border-indigo-200 bg-indigo-50">
                <p class="text-xs text-indigo-600 font-medium">Advance Amount</p>
                <p class="text-2xl font-bold text-indigo-700 mt-1">${{ number_format($offer['advance_amount'] ?? $offer['advance'] ?? 0, 2) }}</p>
            </div>
            <div class="stat-card">
                <p class="text-xs text-slate-500 font-medium">Factor Rate</p>
                <p class="text-2xl font-bold text-slate-800 mt-1">{{ $offer['factor_rate'] ?? '—' }}</p>
            </div>
            <div class="stat-card">
                <p class="text-xs text-slate-500 font-medium">Payback Amount</p>
                <p class="text-2xl font-bold text-slate-800 mt-1">${{ number_format($offer['payback_amount'] ?? $offer['payback'] ?? 0, 2) }}</p>
            </div>
            @if(isset($offer['daily_payment']) || isset($offer['estimated_daily_payment']))
            <div class="stat-card">
                <p class="text-xs text-slate-500 font-medium">Daily Payment</p>
                <p class="text-2xl font-bold text-slate-800 mt-1">${{ number_format($offer['daily_payment'] ?? $offer['estimated_daily_payment'] ?? 0, 2) }}</p>
            </div>
            @endif
        </div>

        @if(isset($offer['term_days']) || isset($offer['estimated_term']))
        <div class="mt-4 pt-4 border-t border-slate-100 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-slate-500 font-medium">Term (Days)</p>
                <p class="text-base font-semibold text-slate-700">{{ $offer['term_days'] ?? $offer['estimated_term'] ?? '—' }}</p>
            </div>
            @if(isset($offer['hold_percentage']))
            <div>
                <p class="text-xs text-slate-500 font-medium">Hold %</p>
                <p class="text-base font-semibold text-slate-700">{{ $offer['hold_percentage'] }}%</p>
            </div>
            @endif
        </div>
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- I. TRANSACTIONS TABLE --}}
{{-- ============================================================ --}}
@if(isset($data['data']['transactions']) || isset($data['transactions']))
@php
    $txData = $data['data']['transactions'] ?? $data['transactions'] ?? [];
    $transactions = $txData['items'] ?? $txData['data'] ?? (isset($txData[0]) ? $txData : []);
    $txTotal = $txData['transactions_total'] ?? $data['data']['transactions_total'] ?? $data['transactions_total'] ?? count($transactions);
    $txTruncated = $txData['transactions_truncated'] ?? $data['data']['transactions_truncated'] ?? $data['transactions_truncated'] ?? false;
    $initialShow = 50;
@endphp
<details class="section-panel" open>
    <summary>Transactions ({{ number_format($txTotal) }} total{{ $txTruncated ? ' — truncated' : '' }})</summary>
    <div class="section-body p-0">
        @if(count($transactions) > 0)
        <div class="overflow-x-auto">
            <table class="data-table" id="txTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>MCA</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $i => $tx)
                    <tr class="tx-row {{ $i >= $initialShow ? 'hidden' : '' }}" data-index="{{ $i }}">
                        <td class="text-slate-400 text-xs">{{ $i + 1 }}</td>
                        <td class="whitespace-nowrap">{{ $tx['date'] ?? $tx['transaction_date'] ?? '—' }}</td>
                        <td class="max-w-xs truncate" title="{{ $tx['description'] ?? '' }}">{{ $tx['description'] ?? '—' }}</td>
                        <td class="font-mono font-semibold whitespace-nowrap {{ ($tx['amount'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            ${{ number_format(abs($tx['amount'] ?? 0), 2) }}
                        </td>
                        <td>
                            @php $type = strtolower($tx['type'] ?? $tx['transaction_type'] ?? ''); @endphp
                            <span class="badge {{ $type === 'credit' ? 'badge-green' : ($type === 'debit' ? 'badge-red' : 'badge-gray') }}">
                                {{ ucfirst($type ?: '—') }}
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-blue">{{ $tx['category'] ?? '—' }}</span>
                        </td>
                        <td class="text-center">
                            @if(isset($tx['is_mca']) && $tx['is_mca'])
                                <span class="badge badge-red">MCA</span>
                            @elseif(isset($tx['mca_flag']) && $tx['mca_flag'])
                                <span class="badge badge-red">MCA</span>
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if(count($transactions) > $initialShow)
        <div class="p-4 text-center border-t border-slate-100" id="loadMoreWrap">
            <p class="text-xs text-slate-400 mb-2">
                Showing <span id="txShowing">{{ $initialShow }}</span> of {{ count($transactions) }} loaded transactions
            </p>
            <button onclick="loadMoreTransactions()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-semibold text-sm px-4 py-2 rounded-lg transition" id="loadMoreBtn">
                Load More
            </button>
        </div>
        @endif

        @else
        <div class="p-6 text-center">
            <p class="text-sm text-slate-400 italic">No transactions available</p>
        </div>
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- J. COMMENTS --}}
{{-- ============================================================ --}}
@if(isset($data['data']['comments']) || isset($data['comments']))
@php $comments = $data['data']['comments'] ?? $data['comments'] ?? []; @endphp
<details class="section-panel">
    <summary>Comments ({{ count($comments) }})</summary>
    <div class="section-body">
        @if(count($comments) > 0)
        <div class="space-y-0">
            @foreach($comments as $comment)
            <div class="timeline-item">
                <p class="text-sm text-slate-700">
                    @if(is_array($comment))
                        {{ $comment['text'] ?? $comment['comment'] ?? $comment['content'] ?? json_encode($comment) }}
                    @else
                        {{ $comment }}
                    @endif
                </p>
                @if(is_array($comment))
                <p class="text-xs text-slate-400 mt-0.5">
                    {{ $comment['created_at'] ?? $comment['date'] ?? '' }}
                    @if(isset($comment['user']) || isset($comment['author']))
                     &mdash; {{ $comment['user'] ?? $comment['author'] ?? '' }}
                    @endif
                    @if(isset($comment['type']))
                     <span class="badge badge-gray ml-1">{{ $comment['type'] }}</span>
                    @endif
                </p>
                @endif
            </div>
            @endforeach
        </div>
        @else
        <p class="text-sm text-slate-400 italic">No comments</p>
        @endif
    </div>
</details>
@endif

{{-- ============================================================ --}}
{{-- K. AUDIT LOG --}}
{{-- ============================================================ --}}
@if(isset($data['data']['audit_log']) || isset($data['audit_log']))
@php $auditLog = $data['data']['audit_log'] ?? $data['audit_log'] ?? []; @endphp
<details class="section-panel">
    <summary>Audit Log ({{ count($auditLog) }})</summary>
    <div class="section-body">
        @if(count($auditLog) > 0)
        <div class="space-y-0">
            @foreach($auditLog as $entry)
            <div class="timeline-item">
                <p class="text-sm text-slate-700">
                    @if(is_array($entry))
                        {{ $entry['action'] ?? $entry['event'] ?? $entry['message'] ?? json_encode($entry) }}
                    @else
                        {{ $entry }}
                    @endif
                </p>
                @if(is_array($entry))
                <p class="text-xs text-slate-400 mt-0.5">
                    {{ $entry['created_at'] ?? $entry['timestamp'] ?? $entry['date'] ?? '' }}
                    @if(isset($entry['user']))
                     &mdash; {{ $entry['user'] }}
                    @endif
                </p>
                @endif
            </div>
            @endforeach
        </div>
        @else
        <p class="text-sm text-slate-400 italic">No audit log entries</p>
        @endif
    </div>
</details>
@endif

@endsection
