@extends('bank-analysis.layout')

@section('content')
<div class="max-w-2xl mx-auto">

    {{-- Error Alert --}}
    @if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-6 flex items-start gap-3">
        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
        </svg>
        <div>
            <p class="font-semibold text-sm">API Error</p>
            <p class="text-sm mt-1">{{ session('error') }}</p>
        </div>
    </div>
    @endif

    {{-- Input Card --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
            <h2 class="text-base font-bold text-slate-800">Analyze Bank Statement</h2>
            <p class="text-sm text-slate-500 mt-0.5">Enter session ID(s) from the Bank Statement Parser API</p>
        </div>

        <form action="/bank-analysis-viewer" method="POST" id="analysisForm" class="p-6 space-y-5">

            {{-- Client ID --}}
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">Client ID</label>
                <input
                    type="number"
                    name="client_id"
                    min="1"
                    required
                    value="{{ old('client_id') }}"
                    class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition"
                    placeholder="e.g. 11"
                >
                <p class="text-xs text-slate-400 mt-1">Balji credentials will be loaded from this client's integration config.</p>
            </div>

            {{-- Session IDs --}}
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">Session ID(s)</label>
                <textarea
                    name="session_ids"
                    rows="3"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition"
                    placeholder="Paste one or more UUIDs (one per line or comma-separated)&#10;e.g. 1e1ced91-bb44-427e-80a9-bee149f2d539"
                >{{ old('session_ids') }}</textarea>
                <p class="text-xs text-slate-400 mt-1">For multi-session analysis, enter multiple UUIDs separated by newlines or commas.</p>
            </div>

            {{-- Include Sections --}}
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Include Sections</label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach($sections as $section)
                    <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer hover:text-slate-800">
                        <input
                            type="checkbox"
                            name="include[]"
                            value="{{ $section }}"
                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            {{ (is_array(old('include')) && in_array($section, old('include'))) ? 'checked' : '' }}
                        >
                        <span>{{ str_replace('_', ' ', ucfirst($section)) }}</span>
                    </label>
                    @endforeach
                </div>
                <p class="text-xs text-slate-400 mt-1.5">Leave all unchecked to include everything.</p>
            </div>

            {{-- Transaction Limit --}}
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">Transaction Limit</label>
                <input
                    type="number"
                    name="transaction_limit"
                    min="1"
                    max="5000"
                    value="{{ old('transaction_limit') }}"
                    class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition"
                    placeholder="Max 5000"
                >
                <p class="text-xs text-slate-400 mt-1">Leave empty to fetch all transactions.</p>
            </div>

            {{-- Submit --}}
            <div class="pt-2">
                <button
                    type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2.5 rounded-lg text-sm transition shadow-sm"
                >
                    Analyze
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
