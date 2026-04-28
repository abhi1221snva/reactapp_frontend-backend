<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmBankStatementSession;
use App\Services\EasifyBankStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CrmBankStatementController extends Controller
{
    // ── Standalone page: all sessions ─────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $conn    = $this->tenantDb($request);
        $perPage = min((int) ($request->input('per_page', 20)), 100);

        $query = CrmBankStatementSession::on($conn)->orderByDesc('created_at');

        if ($request->filled('lead_id')) {
            $query->where('lead_id', (int) $request->input('lead_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginated = $query->paginate($perPage);

        return $this->successResponse('Sessions retrieved.', [
            'sessions' => $paginated->items(),
            'meta'     => [
                'total'        => $paginated->total(),
                'page'         => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    // ── Single session by UUID (no lead_id required) ──────────────────────────

    public function show(Request $request, string $sessionId): JsonResponse
    {
        $conn    = $this->tenantDb($request);
        $session = CrmBankStatementSession::on($conn)
            ->where('session_id', $sessionId)
            ->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'Session not found.'], 404);
        }

        return $this->successResponse('Session retrieved.', $session->toArray());
    }

    // ── Lead-specific ─────────────────────────────────────────────────────────

    public function leadSessions(Request $request, $id): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn     = $this->tenantDb($request);
        $sessions = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->orderByDesc('created_at')
            ->get();

        // Auto-sync any pending/processing sessions
        $this->pollPendingSessions($sessions, $request);

        return $this->successResponse('Lead sessions retrieved.', $sessions->fresh()->toArray());
    }

    public function upload(Request $request, $id): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $this->validate($request, [
            'files'      => 'required|array|min:1|max:10',
            'files.*'    => 'file|mimes:pdf|max:20480',
            'model_tier' => 'nullable|in:lsc_basic,lsc_pro,lsc_max',
        ]);

        $clientId  = $this->tenantId($request);
        $modelTier = $request->input('model_tier', 'lsc_basic');
        $returnUrl = url("easify/webhook/bank-statement/{$clientId}");

        try {
            $service = EasifyBankStatementService::forClient($clientId);
            $result  = $service->analyze(
                $request->file('files'),
                (int) $id,
                (int) $request->auth->id,
                $modelTier,
                $returnUrl
            );

            return $this->successResponse('Analysis started.', $result, 202);
        } catch (\Throwable $e) {
            Log::error('[BankStatement] Upload failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function uploadStandalone(Request $request): JsonResponse
    {
        $this->validate($request, [
            'files'      => 'required|array|min:1|max:10',
            'files.*'    => 'file|mimes:pdf|max:20480',
            'model_tier' => 'nullable|in:lsc_basic,lsc_pro,lsc_max',
            'lead_id'    => 'nullable|integer',
        ]);

        $clientId  = $this->tenantId($request);
        $leadId    = $request->input('lead_id') ? (int) $request->input('lead_id') : null;
        $modelTier = $request->input('model_tier', 'lsc_basic');
        $returnUrl = url("easify/webhook/bank-statement/{$clientId}");

        try {
            $service = EasifyBankStatementService::forClient($clientId);
            $result  = $service->analyze(
                $request->file('files'),
                $leadId,
                (int) $request->auth->id,
                $modelTier,
                $returnUrl
            );

            return $this->successResponse('Analysis started.', $result, 202);
        } catch (\Throwable $e) {
            Log::error('[BankStatement] Standalone upload failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /crm/lead/{id}/bank-statements/analyze-document
     * Analyze an existing document (PDF) via Balji.
     */
    public function analyzeDocument(Request $request, $id): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $this->validate($request, [
            'document_id' => 'required|integer',
            'model_tier'  => 'nullable|in:lsc_basic,lsc_pro,lsc_max',
        ]);

        $clientId  = $this->tenantId($request);
        $conn      = $this->tenantDb($request);
        $docId     = (int) $request->input('document_id');
        $modelTier = $request->input('model_tier', 'lsc_pro');

        // Check if already analyzed
        $existing = CrmBankStatementSession::on($conn)
            ->where('document_id', $docId)
            ->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'This document has already been analyzed.',
                'session' => $existing->toArray(),
            ], 422);
        }

        // Fetch document
        $doc = DB::connection($conn)
            ->table('crm_documents')
            ->where('id', $docId)
            ->where('lead_id', (int) $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Document not found.'], 404);
        }

        // Verify it's a PDF — check file_path first, fall back to file_name
        $ext = strtolower(pathinfo($doc->file_path ?: ($doc->file_name ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return response()->json(['success' => false, 'message' => 'Only PDF documents can be analyzed.'], 422);
        }

        // Resolve file to absolute path
        $absPath = $this->resolveStoragePath($doc->file_path ?? '', $clientId, (int) $id, $doc->file_name ?? '');
        if (!$absPath || !file_exists($absPath)) {
            return response()->json(['success' => false, 'message' => 'File not found on server.'], 404);
        }

        $returnUrl = url("easify/webhook/bank-statement/{$clientId}");
        $fileName  = $doc->file_name ?: basename($doc->file_path);

        try {
            $service = EasifyBankStatementService::forClient($clientId);
            $result  = $service->analyzeFromPath(
                $absPath,
                $fileName,
                (int) $id,
                $docId,
                (int) $request->auth->id,
                $modelTier,
                $returnUrl
            );

            return $this->successResponse('Analysis started.', $result, 202);
        } catch (\Throwable $e) {
            Log::error('[BankStatement] analyzeDocument failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /crm/lead/{id}/bank-statements/by-documents
     * Get sessions indexed by document_id for the documents tab.
     */
    public function byDocuments(Request $request, $id): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn     = $this->tenantDb($request);
        $sessions = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->whereNotNull('document_id')
            ->orderByDesc('created_at')
            ->get();

        // Auto-sync pending sessions
        $this->pollPendingSessions($sessions, $request);

        return $this->successResponse('Document sessions retrieved.', $sessions->fresh()->toArray());
    }

    /**
     * GET /crm/lead/{id}/bank-statements/combined-analysis        (legacy alias)
     * GET /crm/lead/{id}/bank-statements-analysis                 (preferred)
     *
     * Returns a consolidated report for the Compliance tab:
     *   {
     *     combined:   { revenue, deposits, debits, adjustments, avg_balance,
     *                   ledger_balance, total_transactions, nsf, statement_count },
     *     statements: [ { session_id, document_id, file_name, status, analyzed_at,
     *                     created_at, summary: {...}, raw: {...} }, ... ]
     *   }
     *
     * IMPORTANT: Only sessions linked to an uploaded Document (document_id IS NOT NULL)
     * are considered. This ensures the Compliance tab shows exactly the same set of
     * statements as the Documents tab — no orphan/standalone sessions.
     *
     * Aggregation rules:
     *   revenue, deposits, debits, adjustments, total_transactions, nsf → SUM
     *   avg_balance                                                      → AVG across statements
     *   ledger_balance                                                   → latest statement's value
     */
    public function combinedAnalysis(Request $request, $id): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = $this->tenantDb($request);

        // Fetch only completed, document-linked sessions — sorted DESC so [0] = latest.
        // Match exactly what the Documents tab shows (whereNotNull('document_id')).
        $sessions = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->whereNotNull('document_id')
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->get([
                'id', 'session_id', 'document_id', 'file_name', 'status',
                'summary_data', 'mca_analysis', 'monthly_data',
                'fraud_score', 'total_revenue', 'total_deposits',
                'nsf_count', 'analyzed_at', 'created_at',
            ]);

        $count = $sessions->count();

        $emptyCombined = [
            'revenue'            => 0,
            'deposits'           => 0,
            'debits'             => 0,
            'adjustments'        => 0,
            'avg_balance'        => 0,
            'ledger_balance'     => 0,
            'total_transactions' => 0,
            'nsf'                => 0,
            'statement_count'    => 0,
        ];

        if ($count === 0) {
            return $this->successResponse('No completed bank statement analyses found.', [
                'combined'     => $emptyCombined,
                'combined_raw' => [
                    'summary_data' => null,
                    'mca_analysis' => null,
                    'monthly_data' => null,
                ],
                'statements'   => [],
            ]);
        }

        // Accumulators — single pass, O(n), no nested loops.
        $revenue      = 0.0;
        $deposits     = 0.0;
        $debits       = 0.0;
        $adjustments  = 0.0;
        $totalTx      = 0;
        $nsf          = 0;
        $avgBalSum    = 0.0;
        $avgBalCount  = 0;
        $ledgerLatest = 0.0;
        $ledgerSet    = false;

        // Aggregators for combined_raw (merged monthly, MCA lenders, categories).
        $monthsByKey       = [];   // month_key => merged row
        $lendersByName     = [];   // lender name => merged lender row
        $mcaCountSum       = 0;
        $mcaPaymentsSum    = 0.0;
        $mcaAmountSum      = 0.0;
        $mcaCapacityLatest = null; // take first (most recent) capacity block found
        $categoriesByName  = [];   // category name => merged row

        $statements = [];

        foreach ($sessions as $s) {
            $summary = $s->summary_data ?? [];
            if (is_string($summary)) {
                $decoded = json_decode($summary, true);
                $summary = is_array($decoded) ? $decoded : [];
            }

            $mca = $s->mca_analysis ?? [];
            if (is_string($mca)) {
                $decoded = json_decode($mca, true);
                $mca = is_array($decoded) ? $decoded : [];
            }

            $monthlyRaw = $s->monthly_data ?? [];
            if (is_string($monthlyRaw)) {
                $decoded = json_decode($monthlyRaw, true);
                $monthlyRaw = is_array($decoded) ? $decoded : [];
            }

            $sRevenue   = (float) ($summary['true_revenue']       ?? $s->total_revenue   ?? 0);
            $sDeposits  = (float) ($summary['total_credits']      ?? $s->total_deposits  ?? 0);
            $sDebits    = (float) ($summary['total_debits']       ?? 0);
            $sAdjust    = (float) ($summary['adjustments']        ?? 0);
            $sTx        = (int)   ($summary['total_transactions'] ?? 0);

            $nsfBlock = $summary['nsf'] ?? null;
            $sNsf = is_array($nsfBlock)
                ? (int) ($nsfBlock['nsf_fee_count'] ?? 0)
                : (int) ($summary['nsf_count'] ?? $s->nsf_count ?? 0);

            $sAvgBalRaw = $summary['average_daily_balance'] ?? null;
            $sAvgBal    = $sAvgBalRaw !== null ? (float) $sAvgBalRaw : null;

            $sLedgerRaw = $summary['average_ledger_balance'] ?? $summary['ending_balance'] ?? null;
            $sLedgerBal = $sLedgerRaw !== null ? (float) $sLedgerRaw : null;

            // Per-statement aggregation
            $revenue     += $sRevenue;
            $deposits    += $sDeposits;
            $debits      += $sDebits;
            $adjustments += $sAdjust;
            $totalTx     += $sTx;
            $nsf         += $sNsf;

            if ($sAvgBal !== null) {
                $avgBalSum   += $sAvgBal;
                $avgBalCount += 1;
            }
            if (!$ledgerSet && $sLedgerBal !== null) {
                $ledgerLatest = $sLedgerBal;
                $ledgerSet    = true;
            }

            // ── Merge monthly_data.months by month_key ──────────────────────
            $months = isset($monthlyRaw['months']) && is_array($monthlyRaw['months'])
                ? $monthlyRaw['months']
                : (array_is_list($monthlyRaw) ? $monthlyRaw : []);
            foreach ($months as $m) {
                if (!is_array($m)) continue;
                $key = (string) ($m['month_key'] ?? $m['month_name'] ?? '');
                if ($key === '') continue;
                $mNsf = is_array($m['nsf'] ?? null)
                    ? (float) ($m['nsf']['nsf_fee_count'] ?? 0)
                    : (float) ($m['nsf_count'] ?? 0);
                if (!isset($monthsByKey[$key])) {
                    $monthsByKey[$key] = [
                        'month_key'         => $key,
                        'month_name'        => (string) ($m['month_name'] ?? $key),
                        'deposits'          => 0.0,
                        'adjustments'       => 0.0,
                        'true_revenue'      => 0.0,
                        'avg_daily_balance' => 0.0,
                        '_avg_count'        => 0,
                        'nsf_count'         => 0.0,
                        'deposit_count'     => 0.0,
                        'negative_days'     => 0.0,
                        'debits'            => 0.0,
                    ];
                }
                $monthsByKey[$key]['deposits']      += (float) ($m['deposits'] ?? 0);
                $monthsByKey[$key]['adjustments']   += (float) ($m['adjustments'] ?? 0);
                $monthsByKey[$key]['true_revenue']  += (float) ($m['true_revenue'] ?? 0);
                $mAvg = $m['avg_daily_balance'] ?? $m['average_daily_balance'] ?? null;
                if ($mAvg !== null) {
                    $monthsByKey[$key]['avg_daily_balance'] += (float) $mAvg;
                    $monthsByKey[$key]['_avg_count']        += 1;
                }
                $monthsByKey[$key]['nsf_count']     += $mNsf;
                $monthsByKey[$key]['deposit_count'] += (float) ($m['deposit_count'] ?? $m['credit_count'] ?? 0);
                $monthsByKey[$key]['negative_days'] += (float) ($m['negative_days'] ?? 0);
                $monthsByKey[$key]['debits']        += (float) ($m['debits'] ?? $m['total_debits'] ?? 0);
            }

            if ($mcaCapacityLatest === null && isset($monthlyRaw['mca_capacity']) && is_array($monthlyRaw['mca_capacity'])) {
                $mcaCapacityLatest = $monthlyRaw['mca_capacity'];
            }

            // ── Merge MCA lenders by name ────────────────────────────────────
            $mcaCountSum    += (int)   ($mca['total_mca_count'] ?? 0);
            $mcaPaymentsSum += (float) ($mca['total_mca_payments'] ?? 0);
            $mcaAmountSum   += (float) ($mca['total_mca_amount'] ?? 0);
            $sLenders = is_array($mca['lenders'] ?? null) ? $mca['lenders'] : [];
            foreach ($sLenders as $l) {
                if (!is_array($l)) continue;
                $lName = (string) ($l['name'] ?? $l['lender'] ?? 'Unknown');
                if (!isset($lendersByName[$lName])) {
                    $lendersByName[$lName] = [
                        'name'              => $lName,
                        'estimated_payment' => 0.0,
                        'total_amount'      => 0.0,
                        'frequency'         => (string) ($l['frequency'] ?? 'Daily'),
                    ];
                }
                $lendersByName[$lName]['estimated_payment'] += (float) ($l['estimated_payment'] ?? 0);
                $lendersByName[$lName]['total_amount']      += (float) ($l['total_amount'] ?? 0);
            }

            // ── Merge categories by name ─────────────────────────────────────
            $sCats = $summary['categories'] ?? $summary['category_breakdown'] ?? $summary['transaction_categories'] ?? null;
            if (is_array($sCats)) {
                // Normalize: either associative (name=>obj) or list (obj with 'name')
                $isList = array_is_list($sCats);
                foreach ($sCats as $k => $v) {
                    if ($isList) {
                        if (!is_array($v)) continue;
                        $cName = (string) ($v['name'] ?? $v['category'] ?? 'Other');
                        $cCount = (float) ($v['count'] ?? $v['total'] ?? 0);
                        $cAmount = (float) ($v['amount'] ?? $v['total_amount'] ?? 0);
                        $cType  = (string) ($v['type'] ?? 'all');
                    } else {
                        $cName = (string) $k;
                        if (is_array($v)) {
                            $cCount = (float) ($v['count'] ?? $v['total'] ?? 0);
                            $cAmount = (float) ($v['amount'] ?? $v['total_amount'] ?? 0);
                            $cType  = (string) ($v['type'] ?? 'all');
                        } else {
                            $cCount = (float) $v;
                            $cAmount = 0.0;
                            $cType  = 'all';
                        }
                    }
                    if (!isset($categoriesByName[$cName])) {
                        $categoriesByName[$cName] = [
                            'name'   => $cName,
                            'count'  => 0.0,
                            'amount' => 0.0,
                            'type'   => $cType,
                        ];
                    }
                    $categoriesByName[$cName]['count']  += $cCount;
                    $categoriesByName[$cName]['amount'] += $cAmount;
                }
            }

            // Per-statement payload for the Individual Statements view.
            // Include raw blobs so the frontend can render the full detail view
            // identical to the Documents tab without a second API call.
            $statements[] = [
                'session_id'  => $s->session_id,
                'document_id' => $s->document_id,
                'file_name'   => $s->file_name,
                'status'      => $s->status,
                'fraud_score' => $s->fraud_score !== null ? (float) $s->fraud_score : null,
                'analyzed_at' => $s->analyzed_at,
                'created_at'  => $s->created_at,
                'summary'     => [
                    'revenue'            => round($sRevenue, 2),
                    'deposits'           => round($sDeposits, 2),
                    'debits'             => round($sDebits, 2),
                    'adjustments'        => round($sAdjust, 2),
                    'avg_balance'        => $sAvgBal !== null ? round($sAvgBal, 2) : 0,
                    'ledger_balance'     => $sLedgerBal !== null ? round($sLedgerBal, 2) : 0,
                    'total_transactions' => $sTx,
                    'nsf'                => $sNsf,
                ],
                // Raw payload used by BankStatementAnalysisView for the full detail
                // render (monthly breakdown, MCA detection, etc.).
                'raw' => [
                    'summary_data' => $s->summary_data,
                    'mca_analysis' => $s->mca_analysis,
                    'monthly_data' => $s->monthly_data,
                ],
            ];
        }

        $avgBalance = $avgBalCount > 0 ? $avgBalSum / $avgBalCount : 0.0;

        // Finalize monthly rows (compute avg of avg_daily_balance per month).
        $mergedMonths = [];
        foreach ($monthsByKey as $row) {
            $rowAvgCount = (int) $row['_avg_count'];
            $row['avg_daily_balance'] = $rowAvgCount > 0 ? $row['avg_daily_balance'] / $rowAvgCount : 0.0;
            unset($row['_avg_count']);
            $mergedMonths[] = $row;
        }
        // Sort months chronologically by key (YYYY-MM format) when possible.
        usort($mergedMonths, fn($a, $b) => strcmp((string) $a['month_key'], (string) $b['month_key']));

        $mergedLenders = array_values($lendersByName);
        // Sort categories by count DESC for the pie chart.
        $mergedCategories = array_values($categoriesByName);
        usort($mergedCategories, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        $combinedRaw = [
            'summary_data' => [
                'true_revenue'           => round($revenue, 2),
                'total_credits'          => round($deposits, 2),
                'total_debits'           => round($debits, 2),
                'adjustments'            => round($adjustments, 2),
                'total_transactions'     => $totalTx,
                'nsf_count'              => $nsf,
                'nsf'                    => ['nsf_fee_count' => $nsf],
                'average_daily_balance'  => round($avgBalance, 2),
                'average_ledger_balance' => round($ledgerLatest, 2),
                'categories'             => $mergedCategories,
            ],
            'mca_analysis' => [
                'total_mca_count'    => $mcaCountSum,
                'total_mca_payments' => round($mcaPaymentsSum, 2),
                'total_mca_amount'   => round($mcaAmountSum, 2),
                'lenders'            => $mergedLenders,
            ],
            'monthly_data' => [
                'months'       => $mergedMonths,
                'mca_capacity' => $mcaCapacityLatest,
            ],
        ];

        return $this->successResponse('Bank statements analysis retrieved.', [
            'combined' => [
                'revenue'            => round($revenue, 2),
                'deposits'           => round($deposits, 2),
                'debits'             => round($debits, 2),
                'adjustments'        => round($adjustments, 2),
                'avg_balance'        => round($avgBalance, 2),
                'ledger_balance'     => round($ledgerLatest, 2),
                'total_transactions' => $totalTx,
                'nsf'                => $nsf,
                'statement_count'    => $count,
            ],
            'combined_raw' => $combinedRaw,
            'statements'   => $statements,
        ]);
    }

    /**
     * Resolve a stored file_path (public URL) to an absolute filesystem path.
     * Falls back to convention-based path when file_path is empty:
     *   crm_documents/client_{clientId}/lead_{leadId}/{file_name}
     */
    private function resolveStoragePath(string $fileUrl, int $clientId = 0, int $leadId = 0, string $fileName = ''): ?string
    {
        // 1. Try URL-based resolution
        if (!empty($fileUrl)) {
            $appUrl = rtrim(config('app.url'), '/');
            $prefix = $appUrl . '/storage/';

            if (str_starts_with($fileUrl, $prefix)) {
                $relative = substr($fileUrl, strlen($prefix));
                if (Storage::disk('public')->exists($relative)) {
                    return Storage::disk('public')->path($relative);
                }
            }
        }

        // 2. Fallback: build path from client/lead/filename convention
        if ($clientId && $leadId && $fileName) {
            $relative = "crm_documents/client_{$clientId}/lead_{$leadId}/{$fileName}";
            if (Storage::disk('public')->exists($relative)) {
                return Storage::disk('public')->path($relative);
            }
        }

        return null;
    }

    public function summary(Request $request, $id, $sessionId): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = $this->tenantDb($request);
        $row  = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        // Return cached if completed
        if ($row->status === 'completed' && $row->summary_data) {
            return $this->successResponse('Summary retrieved.', $row->summary_data);
        }

        // Otherwise fetch live
        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->getSummary($sessionId);
            return $this->successResponse('Summary retrieved.', $data['data'] ?? $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function transactions(Request $request, $id, $sessionId): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $filters = $request->only(['type', 'date_from', 'date_to']);

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->getTransactions($sessionId, $filters);
            return $this->successResponse('Transactions retrieved.', $data['data'] ?? $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function mcaAnalysis(Request $request, $id, $sessionId): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = $this->tenantDb($request);
        $row  = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        if ($row->status === 'completed' && $row->mca_analysis) {
            return $this->successResponse('MCA analysis retrieved.', $row->mca_analysis);
        }

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->getMcaAnalysis($sessionId);
            return $this->successResponse('MCA analysis retrieved.', $data['data'] ?? $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function monthly(Request $request, $id, $sessionId): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = $this->tenantDb($request);
        $row  = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        if ($row->status === 'completed' && $row->monthly_data) {
            return $this->successResponse('Monthly data retrieved.', $row->monthly_data);
        }

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->getMonthly($sessionId);
            return $this->successResponse('Monthly data retrieved.', $data['data'] ?? $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function refresh(Request $request, $id, $sessionId): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $row     = $service->syncSessionData($sessionId);
            return $this->successResponse('Session refreshed.', $row ? $row->toArray() : []);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, $id, $sessionId): JsonResponse
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = $this->tenantDb($request);
        $row  = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        // Delete remote session on Easify first
        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $service->deleteSession($sessionId);
        } catch (\Throwable $e) {
            Log::warning('[BankStatement] Remote delete failed, proceeding with local delete', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
        }

        $row->delete();

        return $this->successResponse('Session deleted.');
    }

    // ── CSV / PDF Download ──────────────────────────────────────────────────

    /**
     * GET /crm/lead/{id}/bank-statements/{sessionId}/download-csv
     * Proxy CSV download from Easify.
     */
    public function downloadCsv(Request $request, $id, $sessionId)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        // Verify session belongs to this lead
        $conn = $this->tenantDb($request);
        CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        try {
            $service  = EasifyBankStatementService::forClient($this->tenantId($request));
            $response = $service->downloadCsv($sessionId);

            $fileName = "bank-statement-{$sessionId}.csv";

            return response($response->body(), 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /crm/lead/{id}/bank-statements/{sessionId}/pdf
     * Proxy original PDF from Easify (inline or download).
     */
    public function viewPdf(Request $request, $id, $sessionId)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = $this->tenantDb($request);
        $row  = CrmBankStatementSession::on($conn)
            ->where('lead_id', (int) $id)
            ->where('session_id', $sessionId)
            ->firstOrFail();

        $download = (bool) $request->input('download', 0);

        try {
            $service  = EasifyBankStatementService::forClient($this->tenantId($request));
            $response = $service->downloadPdf($sessionId, $download);

            $fileName    = $row->file_name ?: "bank-statement-{$sessionId}.pdf";
            $disposition = $download ? 'attachment' : 'inline';

            return response($response->body(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "{$disposition}; filename=\"{$fileName}\"",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── Transaction Toggles ──────────────────────────────────────────────────

    /**
     * POST /crm/bank-statements/transactions/{transactionId}/toggle-type
     * Toggle a transaction between credit ↔ debit.
     */
    public function toggleTransactionType(Request $request, $transactionId): JsonResponse
    {
        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->toggleTransactionType((int) $transactionId);
            return $this->successResponse('Transaction type toggled.', $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /crm/bank-statements/transactions/{transactionId}/toggle-revenue
     * Toggle revenue classification (true_revenue ↔ adjustment).
     */
    public function toggleRevenueClassification(Request $request, $transactionId): JsonResponse
    {
        $this->validate($request, [
            'current_classification' => 'required|in:true_revenue,adjustment',
        ]);

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->toggleRevenueClassification(
                (int) $transactionId,
                $request->input('current_classification')
            );
            return $this->successResponse('Revenue classification toggled.', $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /crm/bank-statements/transactions/{transactionId}/toggle-mca
     * Toggle MCA status on a transaction.
     */
    public function toggleMcaStatus(Request $request, $transactionId): JsonResponse
    {
        $this->validate($request, [
            'is_mca'      => 'required|boolean',
            'lender_id'   => 'required_if:is_mca,true|nullable|string',
            'lender_name' => 'required_if:is_mca,true|nullable|string',
        ]);

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->toggleMcaStatus(
                (int) $transactionId,
                (bool) $request->input('is_mca'),
                $request->input('lender_id'),
                $request->input('lender_name')
            );
            return $this->successResponse('MCA status toggled.', $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── Reference Data ───────────────────────────────────────────────────────

    /**
     * GET /crm/bank-statements/mca-lenders
     * Get list of known MCA lenders from Easify.
     */
    public function mcaLenders(Request $request): JsonResponse
    {
        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->getMcaLenders();
            return $this->successResponse('MCA lenders retrieved.', $data['lenders'] ?? $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /crm/bank-statements/stats
     * Get overall bank statement statistics from Easify.
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->getStats();
            return $this->successResponse('Stats retrieved.', $data['data'] ?? $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── Learned Patterns ─────────────────────────────────────────────────────

    /**
     * GET /crm/bank-statements/learned-patterns
     * Get learned MCA patterns (paginated).
     */
    public function learnedPatterns(Request $request): JsonResponse
    {
        // Manager+ only
        if (($request->auth->level ?? 0) < 5) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min((int) ($request->input('per_page', 50)), 100);

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->getLearnedPatterns($page, $perPage);
            return $this->successResponse('Learned patterns retrieved.', $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /crm/bank-statements/learned-patterns
     * Clear all learned patterns.
     */
    public function clearLearnedPatterns(Request $request): JsonResponse
    {
        // Manager+ only
        if (($request->auth->level ?? 0) < 5) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->clearLearnedPatterns();
            return $this->successResponse('All learned patterns cleared.', $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /crm/bank-statements/learned-patterns/{patternId}
     * Delete a single learned pattern.
     */
    public function deleteLearnedPattern(Request $request, $patternId): JsonResponse
    {
        // Manager+ only
        if (($request->auth->level ?? 0) < 5) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            $data    = $service->deleteLearnedPattern((int) $patternId);
            return $this->successResponse('Pattern deleted.', $data);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── API Explorer (direct Easify proxy) ─────────────────────────────────

    /**
     * GET /crm/balji/api-explorer
     * Proxy any Easify API endpoint by session ID.
     */
    public function apiExplorer(Request $request): JsonResponse
    {
        // Only level 5+ (manager) can use explorer
        if (($request->auth->level ?? 0) < 5) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $sessionId = $request->input('session_id');
        $endpoint  = $request->input('endpoint', 'session'); // session, summary, transactions, mca-analysis, monthly

        if (!$sessionId) {
            return response()->json(['success' => false, 'message' => 'session_id is required'], 422);
        }

        $endpointMap = [
            'session'       => "/bank-statement/sessions/{$sessionId}",
            'summary'       => "/bank-statement/sessions/{$sessionId}/summary",
            'transactions'  => "/bank-statement/sessions/{$sessionId}/transactions",
            'mca-analysis'  => "/bank-statement/sessions/{$sessionId}/mca-analysis",
            'monthly'       => "/bank-statement/sessions/{$sessionId}/monthly",
        ];

        $path = $endpointMap[$endpoint] ?? null;
        if (!$path) {
            return response()->json(['success' => false, 'message' => "Invalid endpoint: {$endpoint}"], 422);
        }

        $clientId = $this->tenantId($request);
        $fullUrl  = EasifyBankStatementService::BASE_URL . $path;

        try {
            $service = EasifyBankStatementService::forClient($clientId);
            $start   = microtime(true);

            switch ($endpoint) {
                case 'session':      $data = $service->getSession($sessionId); break;
                case 'summary':      $data = $service->getSummary($sessionId); break;
                case 'transactions': $data = $service->getTransactions($sessionId); break;
                case 'mca-analysis': $data = $service->getMcaAnalysis($sessionId); break;
                case 'monthly':      $data = $service->getMonthly($sessionId); break;
                default:             $data = [];
            }

            $ms = round((microtime(true) - $start) * 1000);

            return $this->successResponse('API response received.', [
                'request'  => [
                    'method'   => 'GET',
                    'url'      => $fullUrl,
                    'endpoint' => $endpoint,
                ],
                'response' => $data,
                'meta'     => [
                    'status'   => 200,
                    'duration' => $ms . 'ms',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success'  => false,
                'message'  => $e->getMessage(),
                'request'  => [
                    'method'   => 'GET',
                    'url'      => $fullUrl,
                    'endpoint' => $endpoint,
                ],
            ], 422);
        }
    }

    // ── API Logs ─────────────────────────────────────────────────────────────

    /**
     * GET /crm/bank-statements/logs
     * Read Balji-related log entries from the server log file.
     */
    public function logs(Request $request): JsonResponse
    {
        // Only level 5+ (manager) can view logs
        if (($request->auth->level ?? 0) < 5) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $date    = $request->input('date', date('Y-m-d'));
        $search  = $request->input('search', '');
        $level   = $request->input('level', '');  // INFO, WARNING, ERROR
        $perPage = min((int) ($request->input('per_page', 100)), 500);
        $page    = max(1, (int) $request->input('page', 1));

        // Determine log file path based on environment
        $sapi    = php_sapi_name();
        $logFile = "/var/log/cafmotel/test-backend.{$sapi}-{$date}.log";

        // Fallback paths
        if (!file_exists($logFile)) {
            $logFile = "/var/log/cafmotel/staging-backend.{$sapi}-{$date}.log";
        }
        if (!file_exists($logFile)) {
            $logFile = "/var/log/cafmotel/backend.{$sapi}-{$date}.log";
        }
        if (!file_exists($logFile)) {
            $logFile = storage_path("logs/backend.log.json");
        }

        if (!file_exists($logFile)) {
            return $this->successResponse('No log file found.', [
                'entries'  => [],
                'meta'     => ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'file' => $logFile],
            ]);
        }

        // Read and filter log entries
        $entries = [];
        $handle  = fopen($logFile, 'r');

        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                // Only Balji / BankStatement / EasifyWebhook entries
                if (
                    strpos($line, '[Balji]') === false &&
                    strpos($line, '[BankStatement]') === false &&
                    strpos($line, '[BaljiWebhook]') === false &&
                    strpos($line, 'EasifyWebhook') === false &&
                    strpos($line, 'easify_bank') === false
                ) {
                    continue;
                }

                // Try JSON decode
                $entry = json_decode($line, true);
                if (!$entry) {
                    $entries[] = ['message' => $line, 'level_name' => 'RAW', 'datetime' => null, 'context' => []];
                    continue;
                }

                // Level filter
                if ($level && isset($entry['level_name']) && strtoupper($entry['level_name']) !== strtoupper($level)) {
                    continue;
                }

                // Search filter
                if ($search) {
                    $haystack = strtolower($line);
                    if (strpos($haystack, strtolower($search)) === false) {
                        continue;
                    }
                }

                $entries[] = [
                    'message'    => $entry['message'] ?? '',
                    'level_name' => $entry['level_name'] ?? 'INFO',
                    'datetime'   => $entry['datetime'] ?? null,
                    'context'    => $entry['context'] ?? [],
                ];
            }
            fclose($handle);
        }

        // Reverse for newest first
        $entries = array_reverse($entries);
        $total   = count($entries);
        $offset  = ($page - 1) * $perPage;
        $paged   = array_slice($entries, $offset, $perPage);

        return $this->successResponse('Logs retrieved.', [
            'entries' => $paged,
            'meta'    => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'file'      => basename($logFile),
                'date'      => $date,
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Poll Balji for any pending/processing sessions and sync.
     */
    private function pollPendingSessions($sessions, Request $request): void
    {
        $pending = $sessions->whereIn('status', ['pending', 'processing']);
        if ($pending->isEmpty()) return;

        try {
            $service = EasifyBankStatementService::forClient($this->tenantId($request));
            foreach ($pending as $row) {
                $service->syncSessionData($row->session_id);
            }
        } catch (\Throwable $e) {
            Log::warning('[BankStatement] Polling failed', ['error' => $e->getMessage()]);
        }
    }
}
