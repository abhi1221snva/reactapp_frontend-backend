<?php

namespace App\Http\Controllers;

use App\Services\ExternalBankAnalysisService;
use Illuminate\Http\Request;
use Laravel\Lumen\Routing\Controller as BaseController;

class BankAnalysisViewerController extends BaseController
{
    protected array $validSections = [
        'summary',
        'balances',
        'risk',
        'revenue',
        'monthly_data',
        'mca_analysis',
        'debt_collector_analysis',
        'offer_preview',
        'transactions',
        'comments',
        'audit_log',
    ];

    /**
     * GET /bank-analysis-viewer — show the input form.
     */
    public function index()
    {
        return view('bank-analysis.index', [
            'sections' => $this->validSections,
        ]);
    }

    /**
     * POST /bank-analysis-viewer — call API and render results.
     */
    public function fetch(Request $request)
    {
        $this->validate($request, [
            'client_id'         => 'required|integer|min:1',
            'session_ids'       => 'required|string',
            'include'           => 'nullable|array',
            'include.*'         => 'string|in:' . implode(',', $this->validSections),
            'transaction_limit' => 'nullable|integer|min:1|max:5000',
        ]);

        // Parse session IDs (newline, comma, or space separated)
        $raw      = $request->input('session_ids', '');
        $sessions = array_values(array_filter(
            array_map('trim', preg_split('/[\n,\s]+/', $raw)),
            fn($s) => $s !== ''
        ));

        if (empty($sessions)) {
            return redirect('/bank-analysis-viewer')
                ->withInput()
                ->with('error', 'Please provide at least one Session ID.');
        }

        $clientId         = (int) $request->input('client_id');
        $include          = $request->input('include');
        $transactionLimit = $request->input('transaction_limit');

        try {
            $service = ExternalBankAnalysisService::forClient($clientId);
            $data    = $service->analyze($sessions, $include, $transactionLimit);
            $rawJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return view('bank-analysis.results', [
                'data'       => $data,
                'rawJson'    => $rawJson,
                'sessions'   => $sessions,
                'clientId'   => $clientId,
                'sections'   => $this->validSections,
                'include'    => $include,
                'txLimit'    => $transactionLimit,
            ]);
        } catch (\Throwable $e) {
            return redirect('/bank-analysis-viewer')
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * POST /bank-analysis/fetch — JSON API for React frontend.
     */
    public function fetchApi(Request $request)
    {
        $this->validate($request, [
            'session_ids'       => 'required|array|min:1',
            'session_ids.*'     => 'required|string',
            'include'           => 'nullable|array',
            'include.*'         => 'string|in:' . implode(',', $this->validSections),
            'transaction_limit' => 'nullable|integer|min:1|max:5000',
        ]);

        $sessions         = $request->input('session_ids');
        $include          = $request->input('include');
        $transactionLimit = $request->input('transaction_limit');

        // Use the authenticated user's parent_id (client_id) from JWT
        $clientId = (int) ($request->auth->parent_id ?? 0);
        if ($clientId < 1) {
            return response()->json(['success' => false, 'message' => 'Unable to determine client ID from auth.'], 400);
        }

        try {
            $service = ExternalBankAnalysisService::forClient($clientId);
            $data    = $service->analyze($sessions, $include, $transactionLimit);

            return response()->json([
                'success' => true,
                'message' => 'Full analysis retrieved successfully.',
                'data'    => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
