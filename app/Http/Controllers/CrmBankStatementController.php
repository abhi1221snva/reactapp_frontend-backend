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

        // Verify it's a PDF
        $ext = strtolower(pathinfo($doc->file_path ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return response()->json(['success' => false, 'message' => 'Only PDF documents can be analyzed.'], 422);
        }

        // Resolve file to absolute path
        $absPath = $this->resolveStoragePath($doc->file_path ?? '');
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
     * Resolve a stored file_path (public URL) to an absolute filesystem path.
     */
    private function resolveStoragePath(string $fileUrl): ?string
    {
        if (empty($fileUrl)) return null;

        $appUrl = rtrim(config('app.url'), '/');
        $prefix = $appUrl . '/storage/';

        if (!str_starts_with($fileUrl, $prefix)) return null;

        $relative = substr($fileUrl, strlen($prefix));

        if (!Storage::disk('public')->exists($relative)) return null;

        return Storage::disk('public')->path($relative);
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

        $row->delete();

        return $this->successResponse('Session deleted.');
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
