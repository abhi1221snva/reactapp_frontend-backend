<?php

namespace App\Services;

use App\Model\Client\CrmLenderAPis;
use App\Model\Client\CrmLenderApiLog;
use App\Services\ErrorParserService;
use App\Services\FixSuggestionService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LenderApiService
 *
 * Dynamically executes an outbound API call to a lender using configuration
 * stored in crm_lender_apis.  No lender-specific logic lives here — the
 * service reads everything it needs from the DB record:
 *
 *   • auth_type + auth_credentials   → how to authenticate
 *   • base_url + endpoint_path       → where to send the request
 *   • request_method                 → HTTP verb
 *   • default_headers                → headers added to every request
 *   • payload_mapping                → CRM field_key → lender JSON path
 *   • response_mapping               → how to extract id/status from response
 *   • retry_attempts / timeout_seconds
 *
 * The public interface is `dispatch()`.  The caller provides:
 *   • The API config record (CrmLenderAPis)
 *   • A flat key-value map of the lead's EAV data (field_key → value)
 *   • Context (lead_id, lender_id, user_id) for logging
 *
 * Returns a structured result array — the caller decides what to do with it.
 */
class LenderApiService
{
    // ── Public entry point ─────────────────────────────────────────────────────

    /**
     * Execute a configured lender API call.
     *
     * @param  string          $clientId
     * @param  CrmLenderAPis   $config      API configuration record
     * @param  array<string,mixed> $leadData  Flat EAV map: field_key => value
     * @param  int             $leadId
     * @param  int             $lenderId
     * @param  int             $userId
     * @return array{
     *   success: bool,
     *   response_code: int|null,
     *   response_body: string|null,
     *   parsed: array,
     *   error: string|null,
     *   log_id: int|null,
     *   duration_ms: int
     * }
     */
    public function dispatch(
        string       $clientId,
        CrmLenderAPis $config,
        array        $leadData,
        int          $leadId,
        int          $lenderId,
        int          $userId = 0
    ): array {
        $url          = $config->fullUrl();
        $method       = strtolower($config->request_method ?: 'post');
        $headers      = $this->resolveHeaders($config);
        $payload      = $this->buildPayload($config, $leadData);
        $maxAttempts  = max(1, (int) ($config->retry_attempts ?? 3));
        $timeoutSecs  = max(5,  (int) ($config->timeout_seconds ?? 30));

        $lastResult   = null;
        $attempt      = 0;

        // ── Retry loop ─────────────────────────────────────────────────────────
        while ($attempt < $maxAttempts) {
            $attempt++;
            $startMs = (int) round(microtime(true) * 1000);

            try {
                // Build an Http client with auth and headers
                $client = Http::withHeaders($headers)
                              ->timeout($timeoutSecs)
                              ->retry(1, 0, fn ($e) => !($e instanceof \Illuminate\Http\Client\ConnectionException)); // Never retry timeouts

                $client = $this->applyAuth($client, $config, $clientId);

                // Send request
                $response = $client->{$method}($url, $payload);

                $duration    = (int) round(microtime(true) * 1000) - $startMs;
                $code        = $response->status();
                $body        = $response->body();
                $isSuccess   = $response->successful();
                $status      = $isSuccess ? 'success' : 'http_error';

                $parsed = $this->parseResponse($config, $body);

                $logId = $this->writeLog($clientId, [
                    'crm_lender_api_id' => $config->id,
                    'lead_id'           => $leadId,
                    'lender_id'         => $lenderId,
                    'user_id'           => $userId,
                    'request_url'       => $url,
                    'request_method'    => strtoupper($method),
                    'request_headers'   => $this->safeHeaders($headers),
                    'request_payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'response_code'     => $code,
                    'response_body'     => $body,
                    'status'            => $status,
                    'error_message'     => $isSuccess ? null : "HTTP {$code}",
                    'duration_ms'       => $duration,
                    'attempt'           => $attempt,
                    'created_at'        => Carbon::now(),
                ]);

                // ── Enrich failed logs with structured error analysis ──────────
                if (!$isSuccess && $logId) {
                    $this->enrichLog($clientId, $logId, $code, $body, $leadData);
                }

                $lastResult = [
                    'success'       => $isSuccess,
                    'response_code' => $code,
                    'response_body' => $body,
                    'parsed'        => $parsed,
                    'error'         => $isSuccess ? null : "HTTP {$code}: " . substr($body, 0, 300),
                    'log_id'        => $logId,
                    'duration_ms'   => $duration,
                ];

                if ($isSuccess) {
                    return $lastResult;
                }

                // Non-2xx: retry only on server errors (5xx), not client errors (4xx)
                if ($code >= 400 && $code < 500) {
                    break; // 4xx: retrying won't help
                }

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $duration = (int) round(microtime(true) * 1000) - $startMs;
                $logId    = $this->writeLog($clientId, [
                    'crm_lender_api_id' => $config->id,
                    'lead_id'           => $leadId,
                    'lender_id'         => $lenderId,
                    'user_id'           => $userId,
                    'request_url'       => $url,
                    'request_method'    => strtoupper($method),
                    'request_headers'   => $this->safeHeaders($headers),
                    'request_payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'response_code'     => null,
                    'response_body'     => null,
                    'status'            => 'timeout',
                    'error_message'     => $e->getMessage(),
                    'duration_ms'       => $duration,
                    'attempt'           => $attempt,
                    'created_at'        => Carbon::now(),
                ]);

                $lastResult = [
                    'success'       => false,
                    'response_code' => null,
                    'response_body' => null,
                    'parsed'        => [],
                    'error'         => 'Connection timeout: ' . $e->getMessage(),
                    'log_id'        => $logId,
                    'duration_ms'   => $duration,
                ];

            } catch (\Throwable $e) {
                $duration = (int) round(microtime(true) * 1000) - $startMs;
                // Log suppressed — permission issue with log file path

                $logId = $this->writeLog($clientId, [
                    'crm_lender_api_id' => $config->id,
                    'lead_id'           => $leadId,
                    'lender_id'         => $lenderId,
                    'user_id'           => $userId,
                    'request_url'       => $url,
                    'request_method'    => strtoupper($method),
                    'request_headers'   => $this->safeHeaders($headers),
                    'request_payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'response_code'     => null,
                    'response_body'     => null,
                    'status'            => 'error',
                    'error_message'     => $e->getMessage(),
                    'duration_ms'       => $duration,
                    'attempt'           => $attempt,
                    'created_at'        => Carbon::now(),
                ]);

                $lastResult = [
                    'success'       => false,
                    'response_code' => null,
                    'response_body' => null,
                    'parsed'        => [],
                    'error'         => $e->getMessage(),
                    'log_id'        => $logId,
                    'duration_ms'   => $duration,
                ];

                break; // Unknown errors: don't retry
            }

            // Back-off before retry (exponential: 1s, 2s, 4s …)
            if ($attempt < $maxAttempts) {
                sleep(min(2 ** ($attempt - 1), 16));
            }
        }

        return $lastResult ?? [
            'success'       => false,
            'response_code' => null,
            'response_body' => null,
            'parsed'        => [],
            'error'         => 'No response received after ' . $attempt . ' attempt(s)',
            'log_id'        => null,
            'duration_ms'   => 0,
        ];
    }

    // ── Auth strategies ────────────────────────────────────────────────────────

    /**
     * Apply the correct auth mechanism to the pending HTTP client.
     */
    private function applyAuth(\Illuminate\Http\Client\PendingRequest $client, CrmLenderAPis $config, string $clientId): \Illuminate\Http\Client\PendingRequest
    {
        $creds = $config->auth_credentials ?? [];

        switch ($config->auth_type) {
            case 'bearer':
                $token = $creds['token'] ?? '';
                return $client->withToken($token);

            case 'basic':
                return $client->withBasicAuth(
                    $creds['username'] ?? $config->username ?? '',
                    $creds['password'] ?? $config->password ?? ''
                );

            case 'api_key':
                $key        = $creds['key'] ?? $config->api_key ?? '';
                $headerName = $creds['header_name'] ?? 'X-Api-Key';
                $in         = $creds['in'] ?? 'header';
                if ($in === 'query') {
                    // Will be appended to URL — handled differently; attach as header for now
                    return $client->withQueryParameters([$headerName => $key]);
                }
                return $client->withHeaders([$headerName => $key]);

            case 'oauth2':
                $token = $this->fetchOAuth2Token($creds, $clientId, $config->id);
                if ($token) {
                    // Some APIs (e.g. Salesforce) use "OAuth" prefix instead of "Bearer"
                    $prefix = $creds['auth_header_prefix'] ?? 'Bearer';
                    return $client->withHeaders(['Authorization' => "$prefix $token"]);
                }
                return $client;

            default:
                return $client;
        }
    }

    /**
     * Fetch an OAuth2 token and cache it per API config.
     * Supports grant_type: client_credentials (default) and password (Salesforce-style).
     * Cache key lives in the process (static array) — reused within a single job.
     */
    private static array $tokenCache = [];

    private function fetchOAuth2Token(array $creds, string $clientId, int $apiId): ?string
    {
        $cacheKey = "oauth2_{$clientId}_{$apiId}";

        if (isset(self::$tokenCache[$cacheKey])) {
            return self::$tokenCache[$cacheKey];
        }

        $grantType = $creds['grant_type'] ?? 'client_credentials';

        $params = $grantType === 'password'
            ? [
                'grant_type'    => 'password',
                'client_id'     => $creds['client_id']     ?? '',
                'client_secret' => $creds['client_secret'] ?? '',
                'username'      => $creds['username']       ?? '',
                'password'      => urldecode($creds['password'] ?? ''),
            ]
            : [
                'grant_type'    => 'client_credentials',
                'client_id'     => $creds['client_id']     ?? '',
                'client_secret' => $creds['client_secret'] ?? '',
                'scope'         => $creds['scope']          ?? '',
            ];

        try {
            $response = Http::timeout(15)->asForm()->post($creds['token_url'] ?? '', $params);

            if ($response->successful()) {
                $token = $response->json('access_token');
                if ($token) {
                    self::$tokenCache[$cacheKey] = $token;
                    return $token;
                }
            }
        } catch (\Throwable $e) {
            // Token fetch failed — caller will skip auth
        }

        return null;
    }

    // ── Payload building ───────────────────────────────────────────────────────

    /**
     * Build the outbound payload from the lead's EAV data using payload_mapping.
     *
     * payload_mapping format:
     *   { "business_name": "business.name", "ein": "business.taxID" }
     *
     * Dot-notation paths are expanded into a nested array:
     *   "business.name" → $payload['business']['name']
     *
     * Array index syntax is also supported:
     *   "owners.0.firstName" → $payload['owners'][0]['firstName']
     */
    public function buildPayload(CrmLenderAPis $config, array $leadData): array
    {
        $mapping = $config->payload_mapping;

        if (empty($mapping) || !is_array($mapping)) {
            // No mapping defined — send raw flat EAV data
            return $leadData;
        }

        $payload = [];

        foreach ($mapping as $crmKey => $lenderPath) {
            // Static literal value: key starting with "=" e.g. "=ach" → use "ach" directly
            if (str_starts_with((string) $crmKey, '=')) {
                $value = substr($crmKey, 1);
            } else {
                $value = $leadData[$crmKey] ?? null;
                if ($value === null || $value === '') {
                    continue; // Skip unmapped / empty fields
                }
            }

            // Support array of paths: one source field → multiple lender destinations
            $paths = is_array($lenderPath) ? $lenderPath : [$lenderPath];

            foreach ($paths as $path) {
                $mapped = $value;
                // Normalize US state values to 2-letter abbreviation when path ends in .state
                if (str_ends_with($path, '.state') && strlen((string) $mapped) > 2) {
                    $mapped = self::normalizeState($mapped) ?? $mapped;
                }
                $this->setNestedValue($payload, $path, $mapped);
            }
        }

        return $payload;
    }

    /**
     * Set a value at a dot-notation path in a nested array, supporting
     * integer keys for arrays (e.g. "owners.0.firstName").
     */
    private function setNestedValue(array &$target, string $path, mixed $value): void
    {
        $keys    = explode('.', $path);
        $current = &$target;

        foreach ($keys as $i => $key) {
            $isLast = ($i === count($keys) - 1);

            // Numeric key → ensure parent is a list, not assoc
            if (is_numeric($key)) {
                $key = (int) $key;
            }

            if ($isLast) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
    }

    /**
     * Convert a US state full name to its 2-letter abbreviation.
     * Returns null if the value is not a recognised state name.
     */
    private static function normalizeState(string $value): ?string
    {
        static $map = [
            'alabama'=>'AL','alaska'=>'AK','arizona'=>'AZ','arkansas'=>'AR',
            'california'=>'CA','colorado'=>'CO','connecticut'=>'CT','delaware'=>'DE',
            'florida'=>'FL','georgia'=>'GA','hawaii'=>'HI','idaho'=>'ID',
            'illinois'=>'IL','indiana'=>'IN','iowa'=>'IA','kansas'=>'KS',
            'kentucky'=>'KY','louisiana'=>'LA','maine'=>'ME','maryland'=>'MD',
            'massachusetts'=>'MA','michigan'=>'MI','minnesota'=>'MN','mississippi'=>'MS',
            'missouri'=>'MO','montana'=>'MT','nebraska'=>'NE','nevada'=>'NV',
            'new hampshire'=>'NH','new jersey'=>'NJ','new mexico'=>'NM','new york'=>'NY',
            'north carolina'=>'NC','north dakota'=>'ND','ohio'=>'OH','oklahoma'=>'OK',
            'oregon'=>'OR','pennsylvania'=>'PA','rhode island'=>'RI','south carolina'=>'SC',
            'south dakota'=>'SD','tennessee'=>'TN','texas'=>'TX','utah'=>'UT',
            'vermont'=>'VT','virginia'=>'VA','washington'=>'WA','west virginia'=>'WV',
            'wisconsin'=>'WI','wyoming'=>'WY','district of columbia'=>'DC',
        ];
        return $map[strtolower(trim($value))] ?? null;
    }

    // ── Response parsing ───────────────────────────────────────────────────────

    /**
     * Extract known fields from the API response using response_mapping.
     *
     * response_mapping format:
     *   { "id_field": "data.applicationId", "status_field": "data.status" }
     *
     * Returns a flat assoc of mapped key → value extracted from parsed JSON.
     */
    private function parseResponse(CrmLenderAPis $config, ?string $body): array
    {
        if (empty($body)) {
            return [];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [];
        }

        $mapping = $config->response_mapping;
        if (empty($mapping) || !is_array($mapping)) {
            return $json; // Return raw decoded response when no mapping defined
        }

        $parsed = [];
        foreach ($mapping as $ourKey => $responsePath) {
            $parsed[$ourKey] = Arr::get($json, $responsePath);
        }

        return $parsed;
    }

    // ── Header helpers ─────────────────────────────────────────────────────────

    private function resolveHeaders(CrmLenderAPis $config): array
    {
        $defaults = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        $configured = $config->default_headers;
        if (!is_array($configured)) {
            $configured = [];
        }

        return array_merge($defaults, $configured);
    }

    /**
     * Strip sensitive header values (Authorization) before logging.
     */
    private function safeHeaders(array $headers): array
    {
        $safe = $headers;
        foreach (['Authorization', 'authorization'] as $key) {
            if (isset($safe[$key])) {
                $safe[$key] = substr($safe[$key], 0, 12) . '***';
            }
        }
        return $safe;
    }

    // ── Error Enrichment ───────────────────────────────────────────────────────

    /**
     * Parse and enrich a failed log entry with structured error data.
     * Runs after writeLog() — silently skipped if the columns don't exist yet.
     *
     * @param string     $clientId
     * @param int        $logId       ID returned by writeLog()
     * @param int        $statusCode  HTTP response code
     * @param string|null $body       Raw response body
     * @param array      $leadData    Flat EAV map used to suggest auto-fixes
     */
    private function enrichLog(
        string  $clientId,
        int     $logId,
        int     $statusCode,
        ?string $body,
        array   $leadData
    ): void {
        try {
            $parser    = new ErrorParserService();
            $suggester = new FixSuggestionService();

            $parsedErrors    = $parser->parse($statusCode, $body);
            $fixSuggestions  = $suggester->suggest($parsedErrors, $leadData);

            $isFixable = !empty(array_filter(
                $fixSuggestions,
                fn ($e) => !in_array($e['fix_type'] ?? '', ['unknown'], true)
            ));

            DB::connection("mysql_{$clientId}")
                ->table('crm_lender_api_logs')
                ->where('id', $logId)
                ->update([
                    'error_json'      => json_encode($parsedErrors,   JSON_UNESCAPED_UNICODE),
                    'fix_suggestions' => json_encode($fixSuggestions, JSON_UNESCAPED_UNICODE),
                    'is_fixable'      => $isFixable,
                ]);
        } catch (\Throwable $e) {
            // Non-fatal — error enrichment must never break the main dispatch flow
        }
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    private function writeLog(string $clientId, array $data): ?int
    {
        try {
            // JSON-encode any array values (MySQL JSON columns require strings)
            foreach (['request_headers', 'request_payload'] as $col) {
                if (isset($data[$col]) && is_array($data[$col])) {
                    $data[$col] = json_encode($data[$col], JSON_UNESCAPED_UNICODE);
                }
            }
            return (int) DB::connection("mysql_{$clientId}")
                ->table('crm_lender_api_logs')
                ->insertGetId($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Lead data resolution ───────────────────────────────────────────────────

    /**
     * Resolve a flat EAV map for a lead from the new crm_lead_values table.
     * Falls back gracefully when tables don't exist.
     */
    public function resolveLeadData(string $clientId, int $leadId): array
    {
        try {
            $rows = DB::connection("mysql_{$clientId}")
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();

            // Also pull system columns from crm_leads
            $lead = DB::connection("mysql_{$clientId}")
                ->table('crm_leads')
                ->where('id', $leadId)
                ->first();

            $systemCols = $lead ? (array) $lead : [];

            $merged = array_merge($systemCols, $rows);

            // Compute full_name for lenders that require a combined name field
            if (!isset($merged['full_name'])) {
                $first = trim($merged['first_name'] ?? '');
                $last  = trim($merged['last_name']  ?? '');
                $full  = trim("{$first} {$last}");
                if ($full !== '') {
                    $merged['full_name'] = $full;
                }
            }

            return $merged;
        } catch (\Throwable $e) {
            //Log::warning("LenderApiService: resolveLeadData failed — " . $e->getMessage());
            return [];
        }
    }
}
