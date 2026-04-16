<?php

namespace App\Services;

use App\Model\Client\CrmBankStatementSession;
use App\Model\Client\IntegrationConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EasifyBankStatementService
{
    const PROVIDER  = 'easify_bank_analysis';
    const BASE_URL  = 'https://ai.easify.app/api/v1';
    const TIMEOUT   = 60;
    const AUTH_TTL  = 3500; // ~58 min (token is ~1hr)

    private int    $clientId;
    private string $conn;
    private ?IntegrationConfig $config = null;

    private function __construct(int $clientId)
    {
        $this->clientId = $clientId;
        $this->conn     = "mysql_{$clientId}";
    }

    public static function forClient(int $clientId): self
    {
        return new self($clientId);
    }

    // ── Credentials ──────────────────────────────────────────────────────────

    private function getConfig(): IntegrationConfig
    {
        if ($this->config) return $this->config;

        $this->config = IntegrationConfig::on($this->conn)
            ->where('provider', self::PROVIDER)
            ->where('is_enabled', true)
            ->first();

        if (!$this->config) {
            throw new \RuntimeException('Balji Bank Analysis is not configured. Go to Integrations to set up credentials.');
        }

        return $this->config;
    }

    private function getAuthToken(): string
    {
        $cacheKey = "easify_auth:{$this->clientId}";

        return Cache::remember($cacheKey, self::AUTH_TTL, function () {
            $config = $this->getConfig();
            $email  = $config->api_key;    // stored as api_key
            $pass   = $config->api_secret; // stored as api_secret

            if (!$email || !$pass) {
                throw new \RuntimeException('Balji credentials incomplete. Check API key (email) and secret (password).');
            }

            Log::info('[Balji] Auth request', [
                'client_id' => $this->clientId,
                'url'       => self::BASE_URL . '/auth/login',
                'email'     => $email,
            ]);

            $start    = microtime(true);
            $response = Http::timeout(15)
                ->post(self::BASE_URL . '/auth/login', [
                    'email'    => $email,
                    'password' => $pass,
                ]);
            $duration = round((microtime(true) - $start) * 1000);

            if (!$response->successful()) {
                Log::error('[Balji] Auth failed', [
                    'client_id' => $this->clientId,
                    'status'    => $response->status(),
                    'duration'  => $duration . 'ms',
                    'response'  => substr($response->body(), 0, 1000),
                ]);
                throw new \RuntimeException('Balji authentication failed. Check credentials.');
            }

            Log::info('[Balji] Auth success', [
                'client_id' => $this->clientId,
                'status'    => $response->status(),
                'duration'  => $duration . 'ms',
            ]);

            $token = $response->json('token') ?? $response->json('data.token');
            if (!$token) {
                throw new \RuntimeException('Balji auth response missing token.');
            }

            return $token;
        });
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAuthToken(),
            'Accept'        => 'application/json',
        ])->timeout(self::TIMEOUT);
    }

    /**
     * Retry once if 401 (expired token).
     */
    private function request(string $method, string $url, array $options = []): array
    {
        $fullUrl = self::BASE_URL . $url;
        $start   = microtime(true);

        Log::info('[Balji] API Request', [
            'client_id' => $this->clientId,
            'method'    => strtoupper($method),
            'url'       => $fullUrl,
            'params'    => $options ?: null,
        ]);

        $response = $this->http()->{$method}($fullUrl, $options);

        if ($response->status() === 401) {
            Log::info('[Balji] 401 received, refreshing token and retrying', ['url' => $fullUrl]);
            Cache::forget("easify_auth:{$this->clientId}");
            $response = $this->http()->{$method}($fullUrl, $options);
        }

        $duration = round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            Log::warning('[Balji] API error', [
                'client_id' => $this->clientId,
                'method'    => strtoupper($method),
                'url'       => $fullUrl,
                'status'    => $response->status(),
                'duration'  => $duration . 'ms',
                'response'  => substr($response->body(), 0, 1000),
            ]);
            throw new \RuntimeException('Balji API error: ' . ($response->json('message') ?? $response->status()));
        }

        Log::info('[Balji] API Response', [
            'client_id' => $this->clientId,
            'method'    => strtoupper($method),
            'url'       => $fullUrl,
            'status'    => $response->status(),
            'duration'  => $duration . 'ms',
            'response'  => substr($response->body(), 0, 2000),
        ]);

        return $response->json() ?? [];
    }

    /**
     * Raw HTTP request returning the full Response object (for binary/file downloads).
     * Retries once on 401.
     */
    private function rawRequest(string $method, string $url, array $options = []): \Illuminate\Http\Client\Response
    {
        $fullUrl = self::BASE_URL . $url;
        $start   = microtime(true);

        Log::info('[Balji] Raw API Request', [
            'client_id' => $this->clientId,
            'method'    => strtoupper($method),
            'url'       => $fullUrl,
        ]);

        $response = $this->http()->{$method}($fullUrl, $options);

        if ($response->status() === 401) {
            Cache::forget("easify_auth:{$this->clientId}");
            $response = $this->http()->{$method}($fullUrl, $options);
        }

        $duration = round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            Log::warning('[Balji] Raw API error', [
                'client_id' => $this->clientId,
                'url'       => $fullUrl,
                'status'    => $response->status(),
                'duration'  => $duration . 'ms',
            ]);
            throw new \RuntimeException('Balji API error: ' . ($response->json('message') ?? $response->status()));
        }

        Log::info('[Balji] Raw API Response', [
            'client_id' => $this->clientId,
            'url'       => $fullUrl,
            'status'    => $response->status(),
            'duration'  => $duration . 'ms',
            'size'      => strlen($response->body()),
        ]);

        return $response;
    }

    // ── Core API Methods ─────────────────────────────────────────────────────

    /**
     * Upload bank statement PDFs for analysis.
     *
     * @param array $files Array of UploadedFile objects
     */
    public function analyze(array $files, ?int $leadId, int $userId, string $modelTier = 'lsc_basic', ?string $returnUrl = null): array
    {
        $config = $this->getConfig();
        $token  = $this->getAuthToken();

        $tierMap = [
            'lsc_basic' => 'LSC Basic (Fastest & Most Cost-Effective)',
            'lsc_pro'   => 'LSC Pro (Balanced)',
            'lsc_max'   => 'LSC Max (Most Accurate)',
        ];

        $request = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->timeout(120);

        foreach ($files as $i => $file) {
            $request = $request->attach("statements[$i]", file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        }

        $formData = ['model' => $tierMap[$modelTier] ?? $tierMap['lsc_basic']];
        if ($returnUrl) {
            $formData['return_url'] = $returnUrl;
        }

        $fileNames = array_map(fn($f) => $f->getClientOriginalName(), $files);
        Log::info('[Balji] Upload request', [
            'client_id'  => $this->clientId,
            'url'        => self::BASE_URL . '/bank-statement/analyze',
            'files'      => $fileNames,
            'model_tier' => $modelTier,
            'lead_id'    => $leadId,
            'return_url' => $returnUrl,
        ]);

        $start    = microtime(true);
        $response = $request->post(self::BASE_URL . '/bank-statement/analyze', $formData);
        $duration = round((microtime(true) - $start) * 1000);

        if ($response->status() === 401) {
            Log::warning('[Balji] Upload 401 — token expired', ['client_id' => $this->clientId]);
            Cache::forget("easify_auth:{$this->clientId}");
            throw new \RuntimeException('Balji authentication expired. Please retry.');
        }

        if (!$response->successful()) {
            Log::error('[Balji] Upload failed', [
                'client_id' => $this->clientId,
                'status'    => $response->status(),
                'duration'  => $duration . 'ms',
                'response'  => substr($response->body(), 0, 1000),
            ]);
            throw new \RuntimeException('Balji upload failed: ' . ($response->json('message') ?? $response->status()));
        }

        Log::info('[Balji] Upload response', [
            'client_id' => $this->clientId,
            'status'    => $response->status(),
            'duration'  => $duration . 'ms',
            'response'  => substr($response->body(), 0, 2000),
        ]);

        $data    = $response->json();
        $batchId = $data['batch_id'] ?? null;
        $sessions = $data['sessions'] ?? [];

        $created = [];
        foreach ($sessions as $sess) {
            $row = CrmBankStatementSession::on($this->conn)->create([
                'lead_id'     => $leadId,
                'batch_id'    => $batchId,
                'session_id'  => $sess['session_id'] ?? $sess['id'] ?? uniqid('easify_'),
                'file_name'   => $sess['filename'] ?? $sess['file_name'] ?? null,
                'status'      => 'pending',
                'model_tier'  => $modelTier,
                'uploaded_by' => $userId,
            ]);
            $created[] = $row;
        }

        return [
            'batch_id' => $batchId,
            'sessions' => $created,
        ];
    }

    /**
     * Analyze a bank statement from an existing file path on disk.
     */
    public function analyzeFromPath(string $absPath, string $fileName, ?int $leadId, ?int $documentId, int $userId, string $modelTier = 'lsc_basic', ?string $returnUrl = null): array
    {
        $token = $this->getAuthToken();

        $tierMap = [
            'lsc_basic' => 'LSC Basic (Fastest & Most Cost-Effective)',
            'lsc_pro'   => 'LSC Pro (Balanced)',
            'lsc_max'   => 'LSC Max (Most Accurate)',
        ];

        $request = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->timeout(120);

        $request = $request->attach('statements[0]', file_get_contents($absPath), $fileName);

        $formData = ['model' => $tierMap[$modelTier] ?? $tierMap['lsc_basic']];
        if ($returnUrl) {
            $formData['return_url'] = $returnUrl;
        }

        Log::info('[Balji] Path upload request', [
            'client_id'   => $this->clientId,
            'url'         => self::BASE_URL . '/bank-statement/analyze',
            'file_name'   => $fileName,
            'model_tier'  => $modelTier,
            'lead_id'     => $leadId,
            'document_id' => $documentId,
            'return_url'  => $returnUrl,
        ]);

        $start    = microtime(true);
        $response = $request->post(self::BASE_URL . '/bank-statement/analyze', $formData);
        $duration = round((microtime(true) - $start) * 1000);

        if ($response->status() === 401) {
            Log::warning('[Balji] Path upload 401 — token expired', ['client_id' => $this->clientId]);
            Cache::forget("easify_auth:{$this->clientId}");
            throw new \RuntimeException('Balji authentication expired. Please retry.');
        }

        if (!$response->successful()) {
            Log::error('[Balji] Path upload failed', [
                'client_id' => $this->clientId,
                'status'    => $response->status(),
                'duration'  => $duration . 'ms',
                'response'  => substr($response->body(), 0, 1000),
            ]);
            throw new \RuntimeException('Balji upload failed: ' . ($response->json('message') ?? $response->status()));
        }

        Log::info('[Balji] Path upload response', [
            'client_id' => $this->clientId,
            'status'    => $response->status(),
            'duration'  => $duration . 'ms',
            'response'  => substr($response->body(), 0, 2000),
        ]);

        $data     = $response->json();
        $batchId  = $data['batch_id'] ?? null;
        $sessions = $data['sessions'] ?? [];

        $created = [];
        foreach ($sessions as $sess) {
            $row = CrmBankStatementSession::on($this->conn)->create([
                'lead_id'     => $leadId,
                'document_id' => $documentId,
                'batch_id'    => $batchId,
                'session_id'  => $sess['session_id'] ?? $sess['id'] ?? uniqid('easify_'),
                'file_name'   => $sess['filename'] ?? $sess['file_name'] ?? $fileName,
                'status'      => 'pending',
                'model_tier'  => $modelTier,
                'uploaded_by' => $userId,
            ]);
            $created[] = $row;
        }

        return [
            'batch_id' => $batchId,
            'sessions' => $created,
        ];
    }

    public function getSession(string $sessionId): array
    {
        return $this->request('get', "/bank-statement/sessions/{$sessionId}");
    }

    public function getSummary(string $sessionId): array
    {
        return $this->request('get', "/bank-statement/sessions/{$sessionId}/summary");
    }

    public function getTransactions(string $sessionId, array $filters = []): array
    {
        return $this->request('get', "/bank-statement/sessions/{$sessionId}/transactions?" . http_build_query($filters));
    }

    public function getMcaAnalysis(string $sessionId): array
    {
        return $this->request('get', "/bank-statement/sessions/{$sessionId}/mca-analysis");
    }

    public function getMonthly(string $sessionId): array
    {
        return $this->request('get', "/bank-statement/sessions/{$sessionId}/monthly");
    }

    // ── Session File Endpoints ─────────────────────────────────────────────

    /**
     * Delete a session on Easify (remote cleanup).
     */
    public function deleteSession(string $sessionId): array
    {
        return $this->request('delete', "/bank-statement/sessions/{$sessionId}");
    }

    /**
     * Download transactions as CSV. Returns raw Response with text/csv body.
     */
    public function downloadCsv(string $sessionId): \Illuminate\Http\Client\Response
    {
        return $this->rawRequest('get', "/bank-statement/sessions/{$sessionId}/download");
    }

    /**
     * View/download the original uploaded PDF.
     *
     * @param bool $download true = attachment, false = inline
     */
    public function downloadPdf(string $sessionId, bool $download = false): \Illuminate\Http\Client\Response
    {
        $query = $download ? '?download=1' : '?download=0';
        return $this->rawRequest('get', "/bank-statement/sessions/{$sessionId}/pdf{$query}");
    }

    // ── Transaction Toggle Endpoints ─────────────────────────────────────────

    /**
     * Toggle a transaction between credit ↔ debit.
     */
    public function toggleTransactionType(int $transactionId): array
    {
        return $this->request('post', "/bank-statement/transactions/{$transactionId}/toggle-type");
    }

    /**
     * Toggle revenue classification (true_revenue ↔ adjustment).
     */
    public function toggleRevenueClassification(int $transactionId, string $currentClassification): array
    {
        return $this->request('post', "/bank-statement/transactions/{$transactionId}/toggle-revenue", [
            'current_classification' => $currentClassification,
        ]);
    }

    /**
     * Toggle MCA status on a transaction.
     */
    public function toggleMcaStatus(int $transactionId, bool $isMca, ?string $lenderId = null, ?string $lenderName = null): array
    {
        $payload = ['is_mca' => $isMca];
        if ($isMca) {
            $payload['lender_id']   = $lenderId;
            $payload['lender_name'] = $lenderName;
        }
        return $this->request('post', "/bank-statement/transactions/{$transactionId}/toggle-mca", $payload);
    }

    // ── Reference Data Endpoints ─────────────────────────────────────────────

    /**
     * Get the list of known MCA lenders.
     */
    public function getMcaLenders(): array
    {
        return $this->request('get', '/bank-statement/mca-lenders');
    }

    /**
     * Get overall account statistics.
     */
    public function getStats(): array
    {
        return $this->request('get', '/bank-statement/stats');
    }

    // ── Learned Patterns Endpoints ───────────────────────────────────────────

    /**
     * Get learned MCA patterns (paginated).
     */
    public function getLearnedPatterns(int $page = 1, int $perPage = 50): array
    {
        return $this->request('get', '/bank-statement/learned-patterns?' . http_build_query([
            'page'     => $page,
            'per_page' => $perPage,
        ]));
    }

    /**
     * Clear all learned patterns.
     */
    public function clearLearnedPatterns(): array
    {
        return $this->request('delete', '/bank-statement/learned-patterns');
    }

    /**
     * Delete a single learned pattern.
     */
    public function deleteLearnedPattern(int $patternId): array
    {
        return $this->request('delete', "/bank-statement/learned-patterns/{$patternId}");
    }

    // ── Sync from Balji into local DB ───────────────────────────────────────

    public function syncSessionData(string $sessionId): ?CrmBankStatementSession
    {
        $row = CrmBankStatementSession::on($this->conn)
            ->where('session_id', $sessionId)
            ->first();

        if (!$row) return null;

        // Timeout: if pending/processing for more than 10 minutes, mark as failed
        $createdAt = $row->created_at ? strtotime($row->created_at) : 0;
        if (in_array($row->status, ['pending', 'processing']) && $createdAt > 0 && (time() - $createdAt) > 600) {
            $row->update([
                'status'        => 'failed',
                'error_message' => 'Analysis timed out. The file may not be a valid bank statement.',
            ]);
            return $row->fresh();
        }

        try {
            $session = $this->getSession($sessionId);
            $status  = $session['data']['status'] ?? $session['status'] ?? 'processing';

            if ($status === 'completed' || $status === 'done') {
                $summary  = $this->getSummary($sessionId);
                $mca      = $this->getMcaAnalysis($sessionId);
                $monthly  = $this->getMonthly($sessionId);

                $summaryData = $summary['data'] ?? $summary;
                $mcaData     = $mca['data'] ?? $mca;
                $monthlyData = $monthly['data'] ?? $monthly;

                $row->update([
                    'status'         => 'completed',
                    'summary_data'   => $summaryData,
                    'mca_analysis'   => $mcaData,
                    'monthly_data'   => $monthlyData,
                    'fraud_score'    => $summaryData['fraud_score'] ?? $summaryData['fraud_scoring']['score'] ?? null,
                    'total_revenue'  => $summaryData['total_revenue'] ?? $summaryData['true_revenue'] ?? null,
                    'total_deposits' => $summaryData['total_deposits'] ?? $summaryData['total_credits'] ?? null,
                    'nsf_count'      => $summaryData['nsf_count'] ?? null,
                    'analyzed_at'    => now(),
                ]);
            } elseif ($status === 'failed' || $status === 'error') {
                $row->update([
                    'status'        => 'failed',
                    'error_message' => $session['data']['error'] ?? $session['error'] ?? 'Analysis failed',
                ]);
            } else {
                $row->update(['status' => 'processing']);
            }
        } catch (\Throwable $e) {
            // 404 — try searching the sessions list as fallback
            if (str_contains($e->getMessage(), '404')) {
                $found = $this->findSessionInList($sessionId);
                if ($found && in_array($found['status'] ?? '', ['completed', 'done'])) {
                    // Found completed in list — populate from list data directly
                    $row->update([
                        'status'         => 'completed',
                        'summary_data'   => $found,
                        'fraud_score'    => $found['fraud_score'] ?? null,
                        'total_revenue'  => $found['true_revenue'] ?? $found['total_revenue'] ?? null,
                        'total_deposits' => $found['total_credits'] ?? $found['total_deposits'] ?? null,
                        'nsf_count'      => $found['nsf_fee_count'] ?? null,
                        'analyzed_at'    => now(),
                    ]);
                } else {
                    Log::info('[Balji] Session not ready yet (404)', ['session_id' => $sessionId]);
                    if ($row->status === 'pending') {
                        $row->update(['status' => 'processing']);
                    }
                }
            } else {
                Log::error('[Balji] syncSessionData failed', [
                    'session_id' => $sessionId,
                    'error'      => $e->getMessage(),
                ]);
                $row->update([
                    'status'        => 'failed',
                    'error_message' => 'Sync failed: ' . $e->getMessage(),
                ]);
            }
        }

        return $row->fresh();
    }

    /**
     * Search the sessions list for a session by session_id.
     * Fallback when direct getSession() returns 404.
     */
    public function findSessionInList(string $sessionId): ?array
    {
        try {
            $list = $this->request('get', '/bank-statement/sessions');
            $data = $list['data'] ?? [];
            foreach ($data as $s) {
                if (($s['session_id'] ?? '') === $sessionId) {
                    return $s;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }
}
