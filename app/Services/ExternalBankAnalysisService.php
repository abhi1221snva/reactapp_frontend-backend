<?php

namespace App\Services;

use App\Model\Client\IntegrationConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExternalBankAnalysisService
{
    const PROVIDER  = 'easify_bank_analysis';
    const BASE_URL  = 'https://ai.easify.app/api/v1';
    const TIMEOUT   = 120;
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

    // ── Credentials (same pattern as EasifyBankStatementService) ─────────────

    private function getConfig(): IntegrationConfig
    {
        if ($this->config) return $this->config;

        $this->config = IntegrationConfig::on($this->conn)
            ->where('provider', self::PROVIDER)
            ->where('is_enabled', true)
            ->first();

        if (!$this->config) {
            throw new \RuntimeException('Balji Bank Analysis is not configured for client ' . $this->clientId . '. Go to Integrations to set up credentials.');
        }

        return $this->config;
    }

    private function getAuthToken(): string
    {
        $cacheKey = "easify_fullanalysis_auth:{$this->clientId}";

        return Cache::remember($cacheKey, self::AUTH_TTL, function () {
            $config = $this->getConfig();
            $email  = $config->api_key;    // stored as api_key
            $pass   = $config->api_secret; // stored as api_secret

            if (!$email || !$pass) {
                throw new \RuntimeException('Balji credentials incomplete for client ' . $this->clientId . '. Check API key (email) and secret (password).');
            }

            Log::info('[BankAnalysisViewer] Auth request', [
                'client_id' => $this->clientId,
                'email'     => $email,
            ]);

            $response = Http::timeout(15)
                ->post(self::BASE_URL . '/auth/login', [
                    'email'    => $email,
                    'password' => $pass,
                ]);

            if (!$response->successful()) {
                Log::error('[BankAnalysisViewer] Auth failed', [
                    'client_id' => $this->clientId,
                    'status'    => $response->status(),
                ]);
                throw new \RuntimeException('Balji authentication failed (HTTP ' . $response->status() . '). Check credentials.');
            }

            $token = $response->json('token') ?? $response->json('data.token');
            if (!$token) {
                throw new \RuntimeException('Balji auth response missing token.');
            }

            Log::info('[BankAnalysisViewer] Auth success', ['client_id' => $this->clientId]);
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
     * Make a request with auto-retry on 401 (expired token).
     */
    private function request(string $method, string $url, array $options = []): array
    {
        $fullUrl  = self::BASE_URL . $url;
        $response = $this->http()->{$method}($fullUrl, $options);

        if ($response->status() === 401) {
            Log::info('[BankAnalysisViewer] 401 received, refreshing token', ['url' => $fullUrl]);
            Cache::forget("easify_fullanalysis_auth:{$this->clientId}");
            $response = $this->http()->{$method}($fullUrl, $options);
        }

        if (!$response->successful()) {
            $msg = $response->json('message') ?? $response->json('error') ?? 'HTTP ' . $response->status();
            throw new \RuntimeException("Balji API Error ({$response->status()}): {$msg}");
        }

        return $response->json() ?? [];
    }

    // ── Full Analysis API ────────────────────────────────────────────────────

    /**
     * Convenience wrapper — picks single vs multi based on session count.
     */
    public function analyze(array $sessions, ?array $include = null, ?int $transactionLimit = null): array
    {
        if (count($sessions) === 1) {
            return $this->analyzeSingle($sessions[0], $include, $transactionLimit);
        }

        return $this->analyzeMultiple($sessions, $include, $transactionLimit);
    }

    /**
     * GET /api/v1/bank-statement/full-analysis/{session_id}
     */
    public function analyzeSingle(string $sessionId, ?array $include = null, ?int $transactionLimit = null): array
    {
        $query = $this->buildQuery($include, $transactionLimit);
        $qs    = $query ? '?' . http_build_query($query) : '';

        return $this->request('get', "/bank-statement/full-analysis/{$sessionId}{$qs}");
    }

    /**
     * POST /api/v1/bank-statement/full-analysis
     * Body: { "sessions": ["uuid-1", "uuid-2"] }
     */
    public function analyzeMultiple(array $sessionIds, ?array $include = null, ?int $transactionLimit = null): array
    {
        $query = $this->buildQuery($include, $transactionLimit);
        $qs    = $query ? '?' . http_build_query($query) : '';

        $fullUrl  = self::BASE_URL . "/bank-statement/full-analysis{$qs}";
        $response = $this->http()->post($fullUrl, [
            'sessions' => $sessionIds,
        ]);

        if ($response->status() === 401) {
            Cache::forget("easify_fullanalysis_auth:{$this->clientId}");
            $response = $this->http()->post($fullUrl, [
                'sessions' => $sessionIds,
            ]);
        }

        if (!$response->successful()) {
            $msg = $response->json('message') ?? $response->json('error') ?? 'HTTP ' . $response->status();
            throw new \RuntimeException("Balji API Error ({$response->status()}): {$msg}");
        }

        return $response->json() ?? [];
    }

    private function buildQuery(?array $include, ?int $transactionLimit): array
    {
        $query = [];

        if ($include && count($include) > 0) {
            $query['include'] = implode(',', $include);
        }

        if ($transactionLimit && $transactionLimit > 0) {
            $query['transaction_limit'] = min($transactionLimit, 5000);
        }

        return $query;
    }
}
