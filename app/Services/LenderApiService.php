<?php

namespace App\Services;

use App\Model\Client\Lender;
use App\Services\ErrorParserService;
use App\Services\FixSuggestionService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * LenderApiService
 *
 * Dynamically executes outbound lender API calls using configuration stored
 * in the lenders table. All behaviour is config-driven — no lender-specific
 * hardcoding lives here.
 *
 * Features:
 *   - Pre-flight field validation (required_fields config)
 *   - Idempotency guard (skips duplicate calls within IDEMPOTENCY_WINDOW_SECONDS)
 *   - Smart re-submission (POST on first submission, PUT/PATCH on update)
 *   - Flexible auth (bearer, basic, api_key, oauth2, combined via default_headers)
 *   - Explicit timeouts on all HTTP calls (never hang)
 *   - Controlled retry with per-attempt logging and exponential backoff
 *   - Post-submission document upload with status tracking
 *   - Structured error parsing and fix suggestions
 *   - Full logging of every request/response attempt
 */
class LenderApiService
{
    // ── Constants ──────────────────────────────────────────────────────────────
    private const IDEMPOTENCY_WINDOW_SECONDS = 300;   // 5 minutes
    private const UPLOAD_TIMEOUT_SECONDS     = 60;
    private const DEFAULT_TIMEOUT_SECONDS    = 60;
    private const DEFAULT_RETRY_ATTEMPTS     = 3;

    // ── OAuth2 token cache key prefix (shared across workers via Redis) ────
    private const OAUTH2_CACHE_PREFIX = 'oauth2_token:';

    // ── Public entry point ─────────────────────────────────────────────────────

    /**
     * Execute a configured lender API call.
     *
     * @param  string           $clientId
     * @param  Lender    $config
     * @param  array            $leadData     Flat EAV map: field_key => value
     * @param  int              $leadId
     * @param  int              $lenderId
     * @param  int              $userId
     * @param  array            $documentIds  IDs from crm_documents to upload after success
     * @return array{
     *   success: bool,
     *   idempotent?: bool,
     *   response_code: int|null,
     *   response_body: string|null,
     *   parsed: array,
     *   error: string|null,
     *   validation_errors?: string[],
     *   document_upload?: array,
     *   submission_status: string,
     *   log_id: int|null,
     *   duration_ms: int,
     *   attempts: int
     * }
     */
    public function dispatch(
        string        $clientId,
        Lender $config,
        array         $leadData,
        int           $leadId,
        int           $lenderId,
        int           $userId      = 0,
        array         $documentIds = [],
        int           $attempt     = 1
    ): array {
        // ── 1. Pre-flight validation ───────────────────────────────────────────
        $validationErrors = $this->validateRequiredFields($config, $leadData);
        if (!empty($validationErrors)) {
            try { Log::warning("LenderApiService: pre-flight validation failed", ['lead_id' => $leadId, 'lender_id' => $lenderId, 'missing' => $validationErrors]); } catch (\Throwable) {}
            return [
                'success'           => false,
                'response_code'     => null,
                'response_body'     => null,
                'parsed'            => [],
                'error'             => 'Validation failed — missing required fields: ' . implode(', ', $validationErrors),
                'validation_errors' => $validationErrors,
                'submission_status' => 'failed',
                'log_id'            => null,
                'duration_ms'       => 0,
                'attempts'          => 0,
            ];
        }

        // ── 2. Idempotency guard ───────────────────────────────────────────────
        $idempotentResult = $this->checkIdempotency($clientId, $leadId, $lenderId, $config->id);
        if ($idempotentResult !== null) {
            try { Log::info("LenderApiService: idempotent skip — recent success found", ['lead_id' => $leadId, 'lender_id' => $lenderId, 'log_id' => $idempotentResult['log_id']]); } catch (\Throwable) {}
            // Still attempt document upload if needed (upload may not have run yet)
            $docResult = null;
            if ($config->document_upload_enabled && !empty($documentIds)) {
                $existingId = $this->getExistingSubmissionId($clientId, $leadId, $lenderId);
                if ($existingId) {
                    $docResult = $this->uploadDocuments($clientId, $config, $documentIds, $existingId, $leadId, $lenderId, $userId);
                }
            }
            $idempotentResult['document_upload']   = $docResult;
            $idempotentResult['submission_status'] = $this->resolveSubmissionStatus(true, $docResult);
            return $idempotentResult;
        }

        // ── 3. Resolve URL + method (smart re-submission) ──────────────────────
        $existingId = $this->getExistingSubmissionId($clientId, $leadId, $lenderId);
        [$url, $method] = $this->resolveEndpoint($config, $existingId);

        // ── 4. Build payload + headers ─────────────────────────────────────────
        $headers = $this->resolveHeaders($config);
        $payload = $this->buildPayload($config, $leadData);

        // Inject config-level fields that some lenders require (not from lead data)
        if (!empty($config->sales_rep_email)) {
            $payload['salesRepEmailAddress'] = $config->sales_rep_email;
        }

        $maxAttempts = max(1, (int) ($config->retry_attempts ?? self::DEFAULT_RETRY_ATTEMPTS));
        $timeoutSecs = max(1, (int) ($config->timeout_seconds ?? self::DEFAULT_TIMEOUT_SECONDS));

        $lastResult = null;
        $startMs    = (int) round(microtime(true) * 1000);

        // ── 5. Single HTTP attempt (retry orchestrated by job dispatcher) ────
        try {
            Log::info("LenderApiService: attempt {$attempt}/{$maxAttempts} — {$method} {$url}", [
                'lead_id'   => $leadId,
                'lender_id' => $lenderId,
            ]);
        } catch (\Throwable) { /* log failure must never kill the job */ }

        try {
            $client = Http::withHeaders($headers)->timeout($timeoutSecs);
            $client = $this->applyAuth($client, $config, $clientId);

            $response    = $client->{strtolower($method)}($url, $payload);
            $duration    = (int) round(microtime(true) * 1000) - $startMs;
            $code        = $response->status();
            $body        = $response->body();
            $isSuccess   = $response->successful();
            $status      = $isSuccess ? 'success' : 'http_error';
            $parsed      = $this->parseResponse($config, $body);

            try { Log::info("LenderApiService: attempt {$attempt} → HTTP {$code} ({$duration}ms)", [
                'lead_id'   => $leadId,
                'lender_id' => $lenderId,
                'success'   => $isSuccess,
            ]); } catch (\Throwable) {}

            // ── Build enrichment data BEFORE insert (single-shot) ────────
            $errorJson      = null;
            $fixSuggestions  = null;
            $isFixable       = false;
            if (!$isSuccess) {
                try {
                    $parser       = new ErrorParserService();
                    $suggester    = new FixSuggestionService();
                    $parsedErrors = $parser->parse($code, $body);
                    $suggestions  = $suggester->suggest($parsedErrors, $leadData);
                    $isFixable    = !empty(array_filter($suggestions, fn ($e) => !in_array($e['fix_type'] ?? '', ['unknown'], true)));
                    $errorJson    = json_encode($parsedErrors, JSON_UNESCAPED_UNICODE);
                    $fixSuggestions = json_encode($suggestions, JSON_UNESCAPED_UNICODE);
                } catch (\Throwable) {}
            }

            $logId = $this->writeLog($clientId, [
                'lender_id'         => $lenderId,
                'lead_id'           => $leadId,
                'user_id'           => $userId,
                'request_url'       => $url,
                'request_method'    => strtoupper($method),
                'request_headers'   => $this->safeHeaders($headers),
                'request_payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'response_code'     => $code,
                'response_body'     => $body,
                'status'            => $status,
                'error_message'     => $isSuccess ? null : "HTTP {$code}",
                'error_json'        => $errorJson,
                'fix_suggestions'   => $fixSuggestions,
                'is_fixable'        => $isFixable,
                'duration_ms'       => $duration,
                'attempt'           => $attempt,
                'created_at'        => Carbon::now(),
            ]);

            $lastResult = [
                'success'       => $isSuccess,
                'response_code' => $code,
                'response_body' => $body,
                'parsed'        => $parsed,
                'error'         => $isSuccess ? null : "HTTP {$code}: " . substr($body, 0, 300),
                'log_id'        => $logId,
                'duration_ms'   => $duration,
                'attempts'      => $attempt,
            ];

            if (!$isSuccess && $code >= 400 && $code < 500) {
                // 4xx = client error; retrying won't help
                try { Log::warning("LenderApiService: 4xx error — not retrying", [
                    'lead_id' => $leadId, 'code' => $code,
                ]); } catch (\Throwable) {}
            } elseif (!$isSuccess && $code >= 500 && $attempt < $maxAttempts) {
                // 5xx = server error; signal caller to retry via delayed job dispatch
                $backoff = min(2 ** ($attempt - 1), 16);
                $lastResult['should_retry']        = true;
                $lastResult['retry_attempt']       = $attempt + 1;
                $lastResult['retry_delay_seconds'] = $backoff;
                try { Log::info("LenderApiService: 5xx — signalling retry in {$backoff}s (attempt {$attempt}/{$maxAttempts})"); } catch (\Throwable) {}
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $duration = (int) round(microtime(true) * 1000) - $startMs;
            Log::warning("LenderApiService: timeout on attempt {$attempt}", [
                'lead_id' => $leadId, 'url' => $url, 'error' => $e->getMessage(),
            ]);
            $logId = $this->writeLog($clientId, [
                'lender_id'         => $lenderId,
                'lead_id'           => $leadId,
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
                'attempts'      => $attempt,
            ];

        } catch (\Throwable $e) {
            $duration = (int) round(microtime(true) * 1000) - $startMs;
            $logId = $this->writeLog($clientId, [
                'lender_id'         => $lenderId,
                'lead_id'           => $leadId,
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
                'attempts'      => $attempt,
            ];
        }

        // Final fallback
        if ($lastResult === null) {
            $lastResult = [
                'success'       => false,
                'response_code' => null,
                'response_body' => null,
                'parsed'        => [],
                'error'         => 'No response received on attempt ' . $attempt,
                'log_id'        => null,
                'duration_ms'   => 0,
                'attempts'      => $attempt,
            ];
        }

        if (!$lastResult['success'] && $attempt >= $maxAttempts && empty($lastResult['should_retry'])) {
            try { Log::warning("LenderApiService: all {$maxAttempts} attempts exhausted", ['lead_id' => $leadId, 'lender_id' => $lenderId, 'last_error' => $lastResult['error']]); } catch (\Throwable) {}
        }

        // ── 6. Post-success: persist ID + upload documents ─────────────────────
        $docResult = null;
        if ($lastResult['success']) {
            $savedId = $this->persistSubmissionId(
                $clientId, $leadId, $lenderId, $config, $lastResult['parsed'], $lastResult['response_body'] ?? ''
            );

            if ($config->document_upload_enabled && !empty($documentIds)) {
                $uploadId = $savedId ?? $existingId;
                if ($uploadId) {
                    $docResult = $this->uploadDocuments(
                        $clientId, $config, $documentIds, $uploadId, $leadId, $lenderId, $userId
                    );
                } else {
                    try { Log::warning("LenderApiService: document upload skipped — no application ID available", ['lead_id' => $leadId, 'lender_id' => $lenderId]); } catch (\Throwable) {}
                }
            }
        }

        $lastResult['document_upload']   = $docResult;
        $lastResult['submission_status'] = $this->resolveSubmissionStatus($lastResult['success'], $docResult);

        return $lastResult;
    }

    // ── Smart re-submission ────────────────────────────────────────────────────

    /**
     * Resolve URL and HTTP method.
     * Uses PUT/PATCH to resubmit_endpoint_path when an existing application ID is found
     * AND the lender has explicit resubmit config. Without explicit resubmit config,
     * always uses the original POST endpoint (many lenders don't support PUT updates).
     */
    private function resolveEndpoint(Lender $config, ?string $existingId): array
    {
        if ($existingId && !empty($config->resubmit_endpoint_path)) {
            $path   = str_replace('{id}', $existingId, $config->resubmit_endpoint_path);
            $url    = rtrim($config->base_url, '/') . '/' . ltrim($path, '/');
            $method = strtoupper($config->resubmit_method ?: 'PUT');
        } else {
            // No resubmit config → always use original endpoint + method (safe default)
            $url    = $config->fullUrl();
            $method = strtoupper($config->request_method ?: 'POST');
        }

        return [$url, $method];
    }

    /**
     * Get existing submission ID from crm_lead_lender_api.
     */
    private function getExistingSubmissionId(string $clientId, int $leadId, int $lenderId): ?string
    {
        try {
            $record = DB::connection("mysql_{$clientId}")
                ->table('crm_lead_lender_api')
                ->where('lead_id', $leadId)
                ->where('lender_id', $lenderId)
                ->first();

            return ($record && !empty($record->businessID)) ? (string) $record->businessID : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Idempotency ────────────────────────────────────────────────────────────

    /**
     * Check whether a successful submission was already made within the idempotency window.
     * Returns a result array (with idempotent=true) if found, or null if not.
     */
    private function checkIdempotency(string $clientId, int $leadId, int $lenderId, int $apiConfigId): ?array
    {
        try {
            $since = Carbon::now()->subSeconds(self::IDEMPOTENCY_WINDOW_SECONDS);
            $log   = DB::connection("mysql_{$clientId}")
                ->table('crm_lender_api_logs')
                ->where('lead_id',           $leadId)
                ->where('lender_id',         $lenderId)
                ->where('status',            'success')
                ->where('created_at',        '>=', $since)
                ->orderByDesc('id')
                ->first();

            if (!$log) {
                return null;
            }

            return [
                'success'       => true,
                'idempotent'    => true,
                'response_code' => $log->response_code,
                'response_body' => $log->response_body,
                'parsed'        => json_decode($log->response_body ?? '', true) ?? [],
                'error'         => null,
                'log_id'        => $log->id,
                'duration_ms'   => 0,
                'attempts'      => 0,
            ];
        } catch (\Throwable $e) {
            return null; // If check fails, proceed with the call
        }
    }

    // ── Pre-flight validation ──────────────────────────────────────────────────

    /**
     * Validate that all required_fields in the config are present and non-empty in leadData.
     * Returns an array of missing field keys (empty = all good).
     */
    private function validateRequiredFields(Lender $config, array $leadData): array
    {
        $required = $config->required_fields;
        if (empty($required) || !is_array($required)) {
            return []; // No validation configured — skip
        }

        $missing = [];
        foreach ($required as $fieldKey) {
            $value = $leadData[$fieldKey] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $fieldKey;
            }
        }

        return $missing;
    }

    // ── Submission status resolution ───────────────────────────────────────────

    /**
     * Determine the crm_lender_submissions status based on API + document upload results.
     */
    private function resolveSubmissionStatus(bool $apiSuccess, ?array $docResult): string
    {
        if (!$apiSuccess) {
            return 'failed';
        }
        if ($docResult === null) {
            return 'submitted'; // No docs required
        }
        if ($docResult['success']) {
            return 'submitted'; // All docs uploaded
        }
        return 'partial'; // API succeeded but some/all docs failed
    }

    // ── Persist submission ID ──────────────────────────────────────────────────

    /**
     * Extract and save the application ID from the lender API response.
     * Tries response_mapping first, then scans common key names as fallback.
     */
    public function persistSubmissionId(
        string        $clientId,
        int           $leadId,
        int           $lenderId,
        Lender $config,
        array         $parsed,
        string        $rawBody
    ): ?string {
        // Try response_mapping result first
        $id = $parsed['id_field'] ?? null;

        // Fallback: scan raw response for common ID keys
        if (!$id && $rawBody) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                foreach (['businessID', 'id', 'applicationId', 'response_id', 'submissionId', 'case_id'] as $key) {
                    if (!empty($decoded[$key])) {
                        $id = (string) $decoded[$key];
                        break;
                    }
                    // Support nested: data.id etc.
                    $nested = Arr::get($decoded, 'data.' . $key) ?? Arr::get($decoded, '0.' . $key);
                    if (!empty($nested)) {
                        $id = (string) $nested;
                        break;
                    }
                }
            }
        }

        if (!$id) {
            Log::warning("LenderApiService: no application ID found in response — submission ID not persisted", [
                'lead_id'   => $leadId,
                'lender_id' => $lenderId,
            ]);
            return null;
        }

        try {
            $conn = "mysql_{$clientId}";
            $existing = DB::connection($conn)
                ->table('crm_lead_lender_api')
                ->where('lead_id', $leadId)
                ->where('lender_id', $lenderId)
                ->first();

            if ($existing) {
                DB::connection($conn)->table('crm_lead_lender_api')
                    ->where('id', $existing->id)
                    ->update(['businessID' => $id, 'updated_at' => Carbon::now()]);
            } else {
                DB::connection($conn)->table('crm_lead_lender_api')->insert([
                    'lead_id'         => $leadId,
                    'lender_id'       => $lenderId,
                    'client_id'       => $clientId,
                    'lender_api_type' => $config->type ?? 'generic',
                    'businessID'      => $id,
                    'created_at'      => Carbon::now(),
                    'updated_at'      => Carbon::now(),
                ]);
            }

            Log::info("LenderApiService: application ID persisted", [
                'lead_id'   => $leadId,
                'lender_id' => $lenderId,
                'id'        => $id,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — ID can be recovered from crm_lender_api_logs
            Log::warning("LenderApiService: failed to persist application ID", [
                'error' => $e->getMessage(),
            ]);
        }

        return $id;
    }

    // ── Document upload ────────────────────────────────────────────────────────

    /**
     * Upload documents to the lender API after a successful submission.
     *
     * @return array{uploaded: int[], failed: array, total: int, success: bool, partial: bool}
     */
    public function uploadDocuments(
        string        $clientId,
        Lender $config,
        array         $documentIds,
        string        $applicationId,
        int           $leadId,
        int           $lenderId,
        int           $userId
    ): array {
        $uploaded  = [];
        $failed    = [];

        $endpointTemplate = $config->document_upload_endpoint ?? '';
        if (empty($endpointTemplate)) {
            Log::warning("LenderApiService: document_upload_endpoint not configured", [
                'lead_id' => $leadId, 'lender_id' => $lenderId,
            ]);
            return ['uploaded' => [], 'failed' => [], 'total' => 0, 'success' => false, 'partial' => false];
        }

        $path      = str_replace('{id}', $applicationId, $endpointTemplate);
        $uploadUrl = rtrim($config->base_url, '/') . '/' . ltrim($path, '/');
        $method    = strtolower($config->document_upload_method ?: 'post');
        $fieldName = $config->document_upload_field_name ?: 'file';

        $docs = DB::connection("mysql_{$clientId}")
            ->table('crm_documents')
            ->whereIn('id', $documentIds)
            ->get(['id', 'file_path', 'file_name', 'document_type', 'document_name']);

        $headers = $this->resolveHeaders($config);

        foreach ($docs as $doc) {
            // ── Idempotency: skip if already successfully uploaded ─────────────
            if ($this->documentAlreadyUploaded($clientId, $leadId, $lenderId, $uploadUrl)) {
                Log::info("LenderApiService: document upload skipped (idempotent)", [
                    'doc_id' => $doc->id, 'lead_id' => $leadId,
                ]);
                $uploaded[] = $doc->id;
                continue;
            }

            // ── Resolve file path ─────────────────────────────────────────────
            $absPath = $this->resolveDocumentPath($doc);
            if (!$absPath) {
                $reason = "file_not_found: {$doc->file_path}";
                Log::warning("LenderApiService: document file not found on disk", [
                    'doc_id'    => $doc->id,
                    'file_path' => $doc->file_path,
                ]);
                $failed[] = ['id' => $doc->id, 'file' => $doc->file_name, 'reason' => $reason];
                $this->writeLog($clientId, [
                    'lender_id'         => $lenderId,
                    'lead_id'           => $leadId,
                    'user_id'           => $userId,
                    'request_url'       => $uploadUrl,
                    'request_method'    => 'UPLOAD',
                    'request_headers'   => $this->safeHeaders($headers),
                    'request_payload'   => json_encode(['doc_id' => $doc->id, 'file' => $doc->file_name]),
                    'response_code'     => null,
                    'response_body'     => null,
                    'status'            => 'error',
                    'error_message'     => $reason,
                    'duration_ms'       => 0,
                    'attempt'           => 1,
                    'created_at'        => Carbon::now(),
                ]);
                continue;
            }

            // ── Upload (with 1 retry for HTTP/timeout errors) ─────────────
            $docUploaded = false;
            $maxUploadAttempts = 2;

            for ($uploadAttempt = 1; $uploadAttempt <= $maxUploadAttempts; $uploadAttempt++) {
                $startMs = (int) round(microtime(true) * 1000);
                try {
                    $client = Http::withHeaders($headers)->timeout(self::UPLOAD_TIMEOUT_SECONDS);
                    $client = $this->applyAuth($client, $config, $clientId);

                    $response = $client
                        ->attach($fieldName, file_get_contents($absPath), $doc->file_name)
                        ->{$method}($uploadUrl, ['description' => $doc->document_type ?? '']);

                    $duration = (int) round(microtime(true) * 1000) - $startMs;
                    $isOk     = $response->successful();

                    $this->writeLog($clientId, [
                        'lender_id'         => $lenderId,
                        'lead_id'           => $leadId,
                        'user_id'           => $userId,
                        'request_url'       => $uploadUrl,
                        'request_method'    => 'UPLOAD',
                        'request_headers'   => $this->safeHeaders($headers),
                        'request_payload'   => json_encode(['doc_id' => $doc->id, 'file' => $doc->file_name]),
                        'response_code'     => $response->status(),
                        'response_body'     => substr($response->body(), 0, 2000),
                        'status'            => $isOk ? 'success' : 'http_error',
                        'error_message'     => $isOk ? null : "HTTP {$response->status()}",
                        'duration_ms'       => $duration,
                        'attempt'           => $uploadAttempt,
                        'created_at'        => Carbon::now(),
                    ]);

                    if ($isOk) {
                        $docUploaded = true;
                        Log::info("LenderApiService: document uploaded", [
                            'doc_id' => $doc->id, 'lead_id' => $leadId, 'attempt' => $uploadAttempt,
                        ]);
                        break;
                    }

                    // HTTP error — retry once
                    if ($uploadAttempt < $maxUploadAttempts) {
                        Log::info("LenderApiService: document upload HTTP error, retrying", [
                            'doc_id' => $doc->id, 'code' => $response->status(), 'attempt' => $uploadAttempt,
                        ]);
                        usleep(2_000_000); // 2s before retry
                        continue;
                    }

                    $reason = "HTTP {$response->status()}: " . substr($response->body(), 0, 150);
                    Log::warning("LenderApiService: document upload failed after {$uploadAttempt} attempts", [
                        'doc_id' => $doc->id, 'reason' => $reason,
                    ]);
                    $failed[] = ['id' => $doc->id, 'file' => $doc->file_name, 'reason' => $reason];

                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    $duration = (int) round(microtime(true) * 1000) - $startMs;
                    $this->writeLog($clientId, [
                        'lender_id'         => $lenderId,
                        'lead_id'           => $leadId,
                        'user_id'           => $userId,
                        'request_url'       => $uploadUrl,
                        'request_method'    => 'UPLOAD',
                        'request_headers'   => $this->safeHeaders($headers),
                        'request_payload'   => json_encode(['doc_id' => $doc->id]),
                        'response_code'     => null,
                        'response_body'     => null,
                        'status'            => 'timeout',
                        'error_message'     => $e->getMessage(),
                        'duration_ms'       => $duration,
                        'attempt'           => $uploadAttempt,
                        'created_at'        => Carbon::now(),
                    ]);

                    // Retry once on timeout
                    if ($uploadAttempt < $maxUploadAttempts) {
                        Log::info("LenderApiService: document upload timed out, retrying", [
                            'doc_id' => $doc->id, 'attempt' => $uploadAttempt,
                        ]);
                        usleep(2_000_000);
                        continue;
                    }

                    $reason = 'upload_timeout: ' . $e->getMessage();
                    Log::warning("LenderApiService: document upload timed out after {$uploadAttempt} attempts", [
                        'doc_id' => $doc->id, 'lead_id' => $leadId,
                    ]);
                    $failed[] = ['id' => $doc->id, 'file' => $doc->file_name, 'reason' => $reason];

                } catch (\Throwable $e) {
                    // Unknown errors: no retry (deterministic failure)
                    $reason = $e->getMessage();
                    Log::warning("LenderApiService: document upload exception", [
                        'doc_id' => $doc->id, 'error' => $reason,
                    ]);
                    $failed[] = ['id' => $doc->id, 'file' => $doc->file_name, 'reason' => $reason];
                    break;
                }
            }

            if ($docUploaded) {
                $uploaded[] = $doc->id;
            }
        }

        $total   = count($uploaded) + count($failed);
        $success = $total > 0 && empty($failed);
        $partial = !empty($uploaded) && !empty($failed);

        return compact('uploaded', 'failed', 'total', 'success', 'partial');
    }

    /**
     * Check whether a document upload to this URL was recently successful (idempotency).
     */
    private function documentAlreadyUploaded(string $clientId, int $leadId, int $lenderId, string $uploadUrl): bool
    {
        try {
            $since = Carbon::now()->subSeconds(self::IDEMPOTENCY_WINDOW_SECONDS);
            return DB::connection("mysql_{$clientId}")
                ->table('crm_lender_api_logs')
                ->where('lead_id',      $leadId)
                ->where('lender_id',    $lenderId)
                ->where('request_url',  $uploadUrl)
                ->where('request_method', 'UPLOAD')
                ->where('status',       'success')
                ->where('created_at',   '>=', $since)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Document path resolution ───────────────────────────────────────────────

    /**
     * Resolve the absolute filesystem path for a document record.
     * Uses 3-strategy fallback: absolute path → storage URL → Storage::disk('public').
     */
    public function resolveDocumentPath(object $doc): ?string
    {
        if (empty($doc->file_path)) {
            return null;
        }

        // Strategy 1: already an absolute filesystem path
        if (str_starts_with($doc->file_path, '/') && !str_starts_with($doc->file_path, '//')) {
            if (is_file($doc->file_path)) {
                return $doc->file_path;
            }
        }

        // Strategy 2: URL path → storage/app/public
        $urlPath  = parse_url($doc->file_path, PHP_URL_PATH) ?? $doc->file_path;
        $relative = ltrim($urlPath, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }
        $candidate = rtrim(storage_path('app/public'), '/') . '/' . $relative;
        if (is_file($candidate)) {
            return $candidate;
        }

        // Strategy 3: Storage::disk('public')
        $rel2 = ltrim(parse_url($doc->file_path, PHP_URL_PATH) ?? $doc->file_path, '/');
        if (str_starts_with($rel2, 'storage/')) {
            $rel2 = substr($rel2, strlen('storage/'));
        }
        if (Storage::disk('public')->exists($rel2)) {
            return Storage::disk('public')->path($rel2);
        }

        return null;
    }

    // ── Auth strategies ────────────────────────────────────────────────────────

    /**
     * Apply the correct auth mechanism to the HTTP client.
     * Note: combined auth (e.g. Basic + API key) is handled by setting auth_type = 'basic'
     * and adding the extra header to default_headers in the lender config.
     */
    private function applyAuth(
        \Illuminate\Http\Client\PendingRequest $client,
        Lender $config,
        string $clientId
    ): \Illuminate\Http\Client\PendingRequest {
        $creds = $config->auth_credentials ?? [];

        switch ($config->auth_type) {
            case 'bearer':
                return $client->withToken($creds['token'] ?? '');

            case 'basic':
                return $client->withBasicAuth(
                    $creds['username'] ?? $config->api_username ?? '',
                    $creds['password'] ?? $config->api_password ?? ''
                );

            case 'api_key':
                $key        = $creds['key'] ?? $config->api_key ?? '';
                $headerName = $creds['header_name'] ?? 'X-Api-Key';
                $in         = $creds['in'] ?? 'header';
                if ($in === 'query') {
                    return $client->withQueryParameters([$headerName => $key]);
                }
                return $client->withHeaders([$headerName => $key]);

            case 'oauth2':
                $token = $this->fetchOAuth2Token($creds, $clientId, $config->id);
                if ($token) {
                    $prefix = $creds['auth_header_prefix'] ?? 'Bearer';
                    return $client->withHeaders(['Authorization' => "{$prefix} {$token}"]);
                }
                return $client;

            default:
                return $client; // 'none' or unrecognised
        }
    }

    /**
     * Fetch an OAuth2 token (client_credentials or password grant).
     * Cached in Redis (shared across all queue workers). Falls back to fresh
     * fetch when Redis is unavailable.
     */
    private function fetchOAuth2Token(array $creds, string $clientId, int $apiId): ?string
    {
        $redisKey = self::OAUTH2_CACHE_PREFIX . "{$clientId}:{$apiId}";

        // ── Check Redis cache first ──────────────────────────────────────────
        try {
            $cached = Redis::get($redisKey);
            if ($cached) {
                return $cached;
            }
        } catch (\Throwable) {
            // Redis unavailable — proceed to fetch fresh token
        }

        // ── Fetch new token ──────────────────────────────────────────────────
        $grantType = $creds['grant_type'] ?? 'client_credentials';
        $params    = $grantType === 'password'
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
                $token     = $response->json('access_token');
                $expiresIn = (int) ($response->json('expires_in') ?? 3600);

                if ($token) {
                    // Cache in Redis with TTL = token expiry minus 60s safety buffer
                    $ttl = max(60, $expiresIn - 60);
                    try {
                        Redis::setex($redisKey, $ttl, $token);
                    } catch (\Throwable) {
                        // Redis write failed — token still usable for this request
                    }
                    return $token;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("LenderApiService: OAuth2 token fetch failed", [
                'error'     => $e->getMessage(),
                'client_id' => $clientId,
                'api_id'    => $apiId,
            ]);
        }

        return null;
    }

    // ── Payload building ───────────────────────────────────────────────────────

    /**
     * Build the outbound payload from EAV lead data using payload_mapping.
     * Supports dot-notation paths and array indices (e.g. "owners.0.firstName").
     * Normalises US state values to 2-letter abbreviations where path ends in .state.
     */
    public function buildPayload(Lender $config, array $leadData): array
    {
        $mapping = $config->payload_mapping;
        if (empty($mapping) || !is_array($mapping)) {
            return $leadData;
        }

        $payload = [];
        foreach ($mapping as $crmKey => $lenderPath) {
            // Static literal: key starts with "=" → use the literal value
            if (str_starts_with((string) $crmKey, '=')) {
                $value = substr($crmKey, 1);
            } else {
                $value = $leadData[$crmKey] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
            }

            $paths = is_array($lenderPath) ? $lenderPath : [$lenderPath];
            foreach ($paths as $path) {
                $mapped = $value;
                if (str_ends_with($path, '.state') && strlen((string) $mapped) > 2) {
                    $mapped = self::normalizeState($mapped) ?? $mapped;
                }
                $this->setNestedValue($payload, $path, $mapped);
            }
        }

        return $payload;
    }

    /**
     * Set a value at a dot-notation path in a nested array.
     * Handles integer keys for array indices (e.g. "owners.0.firstName").
     */
    private function setNestedValue(array &$target, string $path, mixed $value): void
    {
        $keys    = explode('.', $path);
        $current = &$target;

        foreach ($keys as $i => $key) {
            $isLast = ($i === count($keys) - 1);
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
     * Normalise a US state full name to its 2-letter abbreviation.
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
     */
    private function parseResponse(Lender $config, ?string $body): array
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
            return $json;
        }
        $parsed = [];
        foreach ($mapping as $ourKey => $responsePath) {
            $parsed[$ourKey] = Arr::get($json, $responsePath);
        }
        return $parsed;
    }

    // ── Header helpers ─────────────────────────────────────────────────────────

    private function resolveHeaders(Lender $config): array
    {
        $defaults   = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $configured = $config->default_headers;
        if (!is_array($configured)) {
            $configured = [];
        }
        return array_merge($defaults, $configured);
    }

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

    // ── Error enrichment ───────────────────────────────────────────────────────

    private function enrichLog(string $clientId, int $logId, int $code, ?string $body, array $leadData): void
    {
        try {
            $parser       = new ErrorParserService();
            $suggester    = new FixSuggestionService();
            $parsedErrors = $parser->parse($code, $body);
            $suggestions  = $suggester->suggest($parsedErrors, $leadData);
            $isFixable    = !empty(array_filter($suggestions, fn ($e) => !in_array($e['fix_type'] ?? '', ['unknown'], true)));

            DB::connection("mysql_{$clientId}")
                ->table('crm_lender_api_logs')
                ->where('id', $logId)
                ->update([
                    'error_json'      => json_encode($parsedErrors,  JSON_UNESCAPED_UNICODE),
                    'fix_suggestions' => json_encode($suggestions,   JSON_UNESCAPED_UNICODE),
                    'is_fixable'      => $isFixable,
                ]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    private function writeLog(string $clientId, array $data): ?int
    {
        try {
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
     * Resolve a flat EAV map for a lead from crm_lead_values + crm_leads system columns.
     */
    public function resolveLeadData(string $clientId, int $leadId): array
    {
        try {
            $rows = DB::connection("mysql_{$clientId}")
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();

            $lead = DB::connection("mysql_{$clientId}")
                ->table('crm_leads')
                ->where('id', $leadId)
                ->first();

            $systemCols = $lead ? (array) $lead : [];
            $merged     = array_merge($systemCols, $rows);

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
            return [];
        }
    }
}
