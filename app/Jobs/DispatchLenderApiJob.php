<?php

namespace App\Jobs;

use App\Model\Client\Lender;
use App\Services\ActivityService;
use App\Services\ApiErrorMapper;
use App\Services\LenderApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * DispatchLenderApiJob
 *
 * Thin job that delegates all API execution to LenderApiService.
 * This replaces the role of the old monolithic SendLeadByLenderApi for
 * lenders whose crm_lender_apis record has been migrated to the new schema
 * (i.e. has base_url or auth_type set).
 *
 * Legacy lenders continue to use SendLeadByLenderApi until migrated.
 *
 * Queue: lender_api_schedule_job
 */
class DispatchLenderApiJob extends Job
{
    private string $clientId;
    private int    $leadId;
    private int    $lenderId;
    private int    $userId;
    private array  $documentIds;
    private int    $attempt;

    public function __construct(
        string $clientId,
        int    $leadId,
        int    $lenderId,
        int    $userId      = 0,
        array  $documentIds = [],
        int    $attempt     = 1
    ) {
        $this->clientId    = $clientId;
        $this->leadId      = $leadId;
        $this->lenderId    = $lenderId;
        $this->userId      = $userId;
        $this->documentIds = $documentIds;
        $this->attempt     = $attempt;
    }

    public function handle(): void
    {
        // ── Acquire Redis concurrency lock (prevents duplicate submissions) ───
        $lockKey  = "lender_submit:{$this->clientId}:{$this->leadId}:{$this->lenderId}";
        $acquired = false;
        try {
            $acquired = Redis::set($lockKey, json_encode([
                'attempt'    => $this->attempt,
                'started_at' => now()->toIso8601String(),
            ]), 'EX', 300, 'NX');
        } catch (\Throwable $e) {
            // Redis unavailable — proceed without lock (graceful degradation)
            Log::warning("DispatchLenderApiJob: Redis lock unavailable, proceeding without lock", [
                'lead_id' => $this->leadId, 'lender_id' => $this->lenderId, 'error' => $e->getMessage(),
            ]);
            $acquired = true;
        }

        if (!$acquired) {
            Log::info("DispatchLenderApiJob: skipped — concurrent lock active", [
                'lead_id' => $this->leadId, 'lender_id' => $this->lenderId,
            ]);
            return;
        }

        try {
            $this->executeDispatch($lockKey);
        } finally {
            try { Redis::del($lockKey); } catch (\Throwable) {}
        }
    }

    private function executeDispatch(string $lockKey): void
    {
        // ── Load API config ────────────────────────────────────────────────────
        $config = Lender::on("mysql_{$this->clientId}")
            ->where('id', $this->lenderId)
            ->where('api_status', '1')
            ->first();

        if (!$config) {
            return;
        }

        // ── Infer config for legacy lenders not yet migrated to new schema ────
        if (!$config->isNewStyle() && !empty($config->lender_api_type)) {
            $this->inferLegacyConfig($config);
        }

        // ── Resolve lead data ─────────────────────────────────────────────────
        $svc      = new LenderApiService();
        $leadData = $svc->resolveLeadData($this->clientId, $this->leadId);

        if (empty($leadData)) {
            return;
        }

        // ── Execute (single attempt — retry handled below) ────────────────────
        $result = $svc->dispatch(
            clientId:    $this->clientId,
            config:      $config,
            leadData:    $leadData,
            leadId:      $this->leadId,
            lenderId:    $this->lenderId,
            userId:      $this->userId,
            documentIds: $this->documentIds,
            attempt:     $this->attempt
        );

        // ── Handle retry if signalled by LenderApiService ─────────────────────
        if (!empty($result['should_retry'])) {
            $nextAttempt = $result['retry_attempt'] ?? ($this->attempt + 1);
            $delaySecs   = $result['retry_delay_seconds'] ?? 5;

            try { Redis::del($lockKey); } catch (\Throwable) {}

            dispatch(new self(
                $this->clientId, $this->leadId, $this->lenderId,
                $this->userId, $this->documentIds, $nextAttempt
            ))->onConnection('redis')->onQueue('default')->delay($delaySecs);

            Log::info("DispatchLenderApiJob: retry scheduled", [
                'lead_id'      => $this->leadId,
                'lender_id'    => $this->lenderId,
                'next_attempt' => $nextAttempt,
                'delay_seconds' => $delaySecs,
            ]);
            return; // Don't persist "failed" status — retry is pending
        }

        // ── Derive update values ───────────────────────────────────────────────
        $submissionStatus = $result['submission_status'] ?? ($result['success'] ? 'submitted' : 'failed');
        $apiError         = $result['error'] ?? null;
        $docUpload        = $result['document_upload'] ?? null;

        $docUploadStatus = 'none';
        $docUploadNotes  = null;

        if ($docUpload !== null) {
            $uploaded = count($docUpload['uploaded'] ?? []);
            $failed   = count($docUpload['failed']   ?? []);
            $total    = $docUpload['total'] ?? ($uploaded + $failed);

            if ($total > 0) {
                if ($failed === 0) {
                    $docUploadStatus = 'success';
                } elseif ($uploaded > 0) {
                    $docUploadStatus = 'partial';
                } else {
                    $docUploadStatus = 'failed';
                }
                $docUploadNotes = "Uploaded: {$uploaded} / Failed: {$failed}";
            }
        }

        // ── Persist status + activity log atomically ───────────────────────────
        try {
            $leadId   = $this->leadId;
            $lenderId = $this->lenderId;
            $userId   = $this->userId;
            $clientId = $this->clientId;

            // Resolve lender name for human-readable activity messages
            $lenderName = DB::connection("mysql_{$clientId}")
                ->table('crm_lender')
                ->where('id', $lenderId)
                ->value('lender_name') ?? "Lender #{$lenderId}";

            $validationErrors = $result['validation_errors'] ?? [];
            $responseCode     = $result['response_code']     ?? null;
            $durationMs       = $result['duration_ms']       ?? null;
            $logId            = $result['log_id']            ?? null;
            $responseBody     = $result['response_body']     ?? null;

            // Build structured meta for modal + display
            $meta = [
                'lender_name'       => $lenderName,
                'lender_id'         => $lenderId,
                'success'           => $result['success'],
                'response_code'     => $responseCode,
                'duration_ms'       => $durationMs,
                'submission_status' => $submissionStatus,
                'log_id'            => $logId,
                'attempts'          => $result['attempts'] ?? 1,
            ];
            if (!empty($validationErrors)) {
                $meta['validation_errors'] = $validationErrors;
            }
            if ($responseBody !== null) {
                // Truncate to 4 KB — enough for modal display without bloating the timeline response
                $meta['response_body'] = mb_substr($responseBody, 0, 4096);
            }
            if ($docUpload !== null) {
                $meta['doc_upload'] = [
                    'uploaded' => count((array)($docUpload['uploaded'] ?? [])),
                    'failed'   => count((array)($docUpload['failed']   ?? [])),
                    'total'    => $docUpload['total'] ?? 0,
                ];
            }

            // Fetch enriched fix data written by enrichLog() in LenderApiService
            if ($logId) {
                $logRow = DB::connection("mysql_{$clientId}")
                    ->table('crm_lender_api_logs')
                    ->where('id', $logId)
                    ->select(['fix_suggestions', 'is_fixable', 'status'])
                    ->first();
                if ($logRow) {
                    $raw = $logRow->fix_suggestions ?? '[]';
                    $fixSuggestions = is_array($raw) ? $raw : json_decode($raw, true);
                    if (!empty($fixSuggestions)) {
                        $meta['fix_suggestions'] = $fixSuggestions;
                    }
                    $meta['is_fixable'] = (bool) $logRow->is_fixable;
                    $meta['api_status'] = $logRow->status; // 'success' | 'error' | 'timeout'
                }
            }

            // Human-readable subject + body (no raw JSON)
            $activitySubject = ActivityService::lenderApiSubject(
                $lenderName, $result['success'], $apiError, $validationErrors, $responseCode, $docUpload
            );
            $activityBody = ActivityService::lenderApiBody(
                $result['success'], $apiError, $validationErrors, $responseCode, $durationMs, $docUpload
            );

            // Build structured error_messages via ApiErrorMapper for UI display
            $rawMapping     = $config->payload_mapping ?? '{}';
            $payloadMapping = is_array($rawMapping) ? $rawMapping : (json_decode($rawMapping, true) ?: []);
            $errorMessages = null;
            if (!$result['success']) {
                if (!empty($meta['fix_suggestions'])) {
                    $errorMessages = ApiErrorMapper::fromFixSuggestions($meta['fix_suggestions'], $payloadMapping);
                } elseif ($responseBody) {
                    $errorMessages = ApiErrorMapper::map($responseCode ?? 400, $responseBody, [], $payloadMapping);
                } elseif (!empty($validationErrors)) {
                    $errorMessages = array_map(fn ($msg) => [
                        'label'    => $msg,
                        'field'    => '',
                        'message'  => $msg,
                        'fix_type' => 'required',
                        'expected' => '',
                    ], $validationErrors);
                }
            }

            DB::connection("mysql_{$clientId}")->transaction(
                function () use (
                    $clientId, $leadId, $lenderId, $userId,
                    $submissionStatus, $apiError, $docUploadStatus, $docUploadNotes,
                    $activitySubject, $activityBody, $meta, $errorMessages
                ) {
                    $now = Carbon::now();

                    DB::connection("mysql_{$clientId}")
                        ->table('crm_lender_submissions')
                        ->where('lead_id', $leadId)
                        ->where('lender_id', $lenderId)
                        ->update([
                            'submission_status' => $submissionStatus,
                            'api_error'         => $apiError,
                            'error_messages'    => $errorMessages ? json_encode($errorMessages) : null,
                            'doc_upload_status' => $docUploadStatus,
                            'doc_upload_notes'  => $docUploadNotes,
                            'updated_at'        => $now,
                        ]);

                    ActivityService::log(
                        clientId:   $clientId,
                        leadId:     $leadId,
                        type:       'lender_api_result',
                        subject:    $activitySubject,
                        body:       $activityBody,
                        meta:       $meta,
                        userId:     $userId,
                        sourceType: 'lender_api'
                    );
                }
            );
        } catch (\Throwable $e) {
            Log::error(
                "DispatchLenderApiJob: failed to persist result for lead {$this->leadId}, lender {$this->lenderId}: "
                . $e->getMessage()
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ── Legacy lender config inference ────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════

    /** Endpoint path suffixes for each legacy lender_api_type. */
    private const LEGACY_ENDPOINTS = [
        'ondeck'            => 'application',
        'credibly'          => 'submission-api/submitApplication',
        'fox_partner'       => 'submissions',
        'lendini'           => 'postNewDeal',
        'specialty'         => 'deal-submission',
        'forward_financing' => 'lead',
        'cancapital'        => 'createapplication',
        'bitty_advance'     => '',
        'biz2credit'        => '',
    ];

    /** Maps lender_api_type → column name in crm_lender_apis_label_setting. */
    private const LEGACY_LABEL_COLUMNS = [
        'ondeck'            => 'ondeck_label',
        'credibly'          => 'credibly_label',
        'fox_partner'       => 'fox_partner_label',
        'lendini'           => 'lendini_label',
        'specialty'         => 'specialty_label',
        'forward_financing' => 'forward_financing_label',
        'cancapital'        => 'cancapital_label',
        'bitty_advance'     => 'bittyadvance_label',
        'biz2credit'        => 'biz2credit_label',
    ];

    /**
     * Populate endpoint_path, auth_type, auth_credentials, default_headers,
     * and payload_mapping on an in-memory Lender model so the config-driven
     * LenderApiService can handle legacy lenders without DB migration.
     */
    private function inferLegacyConfig(Lender $config): void
    {
        $type = $config->lender_api_type;

        // ── 1. Endpoint path ─────────────────────────────────────────────────
        $config->endpoint_path = self::LEGACY_ENDPOINTS[$type] ?? '';

        // ── 2. Auth + headers ────────────────────────────────────────────────
        $this->inferLegacyAuth($config, $type);

        // ── 3. Payload mapping from crm_lender_apis_label_setting ────────────
        $mapping = $this->buildLegacyPayloadMapping($type);

        // ── 4. Add per-lender static/literal fields ─────────────────────────
        $this->addLegacyStaticFields($type, $mapping, $config);

        $config->payload_mapping = $mapping;

        try {
            Log::info("DispatchLenderApiJob: inferred legacy config for {$type}", [
                'lead_id'       => $this->leadId,
                'lender_id'     => $this->lenderId,
                'endpoint_path' => $config->endpoint_path,
                'auth_type'     => $config->auth_type,
                'mapping_count' => is_array($config->payload_mapping) ? count($config->payload_mapping) : 0,
            ]);
        } catch (\Throwable) { /* log failure must never kill the job */ }
    }

    /**
     * Set auth_type, auth_credentials, and default_headers based on the
     * legacy per-lender authentication patterns.
     */
    private function inferLegacyAuth(Lender $config, string $type): void
    {
        switch ($type) {
            case 'ondeck':
                // Basic auth + custom Apikey header
                $config->auth_type        = 'basic';
                $config->auth_credentials = [
                    'username' => $config->api_username ?? '',
                    'password' => $config->api_password ?? '',
                ];
                $config->default_headers  = ['Apikey' => $config->api_key ?? ''];
                break;

            case 'credibly':
                // Bearer token (api_key)
                $config->auth_type        = 'bearer';
                $config->auth_credentials = ['token' => $config->api_key ?? ''];
                break;

            case 'fox_partner':
                // OAuth2 client_credentials
                $config->auth_type        = 'oauth2';
                $config->auth_credentials = [
                    'grant_type'    => 'client_credentials',
                    'token_url'     => 'https://identity.funderone.io/connect/token',
                    'client_id'     => $config->api_username ?? '',
                    'client_secret' => $config->api_password ?? '',
                    'scope'         => '',
                ];
                break;

            case 'lendini':
                // Custom header token-v
                $config->auth_type        = 'api_key';
                $config->auth_credentials = [
                    'key'         => $config->api_key ?? '',
                    'header_name' => 'token-v',
                    'in'          => 'header',
                ];
                break;

            case 'specialty':
            case 'forward_financing':
                // Custom header x-api-key
                $config->auth_type        = 'api_key';
                $config->auth_credentials = [
                    'key'         => $config->api_key ?? '',
                    'header_name' => 'x-api-key',
                    'in'          => 'header',
                ];
                break;

            case 'cancapital':
                // OAuth2 password grant with 'OAuth' prefix
                $config->auth_type        = 'oauth2';
                $config->auth_credentials = [
                    'grant_type'         => 'password',
                    'token_url'          => $config->auth_url ?? '',
                    'client_id'          => $config->api_client_id ?? '',
                    'client_secret'      => $config->api_key ?? '',
                    'username'           => $config->api_username ?? '',
                    'password'           => $config->api_password ?? '',
                    'auth_header_prefix' => 'OAuth',
                ];
                // CanCapital expects text/plain content-type
                $config->default_headers = ['Content-Type' => 'text/plain'];
                break;

            case 'bitty_advance':
            case 'biz2credit':
                // These use custom full URLs; auth varies
                $config->auth_type        = 'api_key';
                $config->auth_credentials = [
                    'key'         => $config->api_key ?? '',
                    'header_name' => 'x-api-key',
                    'in'          => 'header',
                ];
                break;

            default:
                // Unknown legacy type — leave as-is (no auth)
                break;
        }
    }

    /**
     * Build payload_mapping from the legacy crm_lender_apis_label_setting table.
     *
     * Joins with crm_label to get column_name (= EAV field_key), and reads
     * the lender-specific path from the appropriate column.  When multiple
     * field_keys map to the same lender path, uses array format so
     * LenderApiService::buildPayload() populates every path.
     *
     * @return array<string, string|string[]>  field_key => lender_path(s)
     */
    private function buildLegacyPayloadMapping(string $type): array
    {
        $lenderCol = self::LEGACY_LABEL_COLUMNS[$type] ?? null;
        if (!$lenderCol) {
            return [];
        }

        try {
            $rows = DB::connection("mysql_{$this->clientId}")
                ->table('crm_lender_apis_label_setting as ls')
                ->join('crm_label as cl', 'cl.id', '=', 'ls.crm_label_id')
                ->whereNotNull("ls.{$lenderCol}")
                ->where("ls.{$lenderCol}", '!=', '')
                ->select("cl.column_name", "ls.{$lenderCol} as lender_path")
                ->get();

            $mapping = [];
            foreach ($rows as $row) {
                $key  = $row->column_name;
                $path = $row->lender_path;

                if (!isset($mapping[$key])) {
                    $mapping[$key] = $path;
                } else {
                    // Same field_key maps to multiple lender paths — use array
                    $existing = is_array($mapping[$key]) ? $mapping[$key] : [$mapping[$key]];
                    $existing[] = $path;
                    $mapping[$key] = $existing;
                }
            }

            // Consolidate first_name + last_name → full_name when both map to
            // the same lender path (legacy code concatenated them).
            // resolveLeadData() already creates full_name = first + last.
            $this->consolidateNamePaths($mapping);

            // Rewrite paths to match each lender's expected nested structure
            // (array indices, wrapper keys, sub-object groupings).
            $this->rewriteLegacyPaths($type, $mapping);

            return $mapping;
        } catch (\Throwable $e) {
            try {
                Log::warning("DispatchLenderApiJob: failed to build legacy payload mapping", [
                    'type'  => $type,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {}
            return [];
        }
    }

    // ── Path rewriting rules per lender ──────────────────────────────────────
    // Ordered most-specific first. Each rule is [prefix => replacement].
    // When a lender path starts with the prefix, it's replaced.
    // Replacement can be a string or array (multi-target).

    /**
     * Rewrite label-setting paths to match each lender's expected nested
     * JSON structure (array indices, wrapper keys, sub-object groupings).
     */
    private function rewriteLegacyPaths(string $type, array &$mapping): void
    {
        $rules = $this->getPathRewriteRules($type);
        if (empty($rules)) {
            return;
        }

        $rewritten = [];
        foreach ($mapping as $fieldKey => $paths) {
            $pathList = (array) $paths;
            $newPaths = [];
            foreach ($pathList as $p) {
                $result = $this->applyRewriteRules($p, $rules);
                if (is_array($result)) {
                    $newPaths = array_merge($newPaths, $result);
                } else {
                    $newPaths[] = $result;
                }
            }
            $newPaths = array_unique($newPaths);
            $rewritten[$fieldKey] = count($newPaths) === 1 ? $newPaths[0] : $newPaths;
        }
        $mapping = $rewritten;
    }

    /**
     * Get ordered path-rewrite rules for a lender type.
     * Rules are tried most-specific first (longest prefix).
     *
     * @return array<string, string|string[]>  prefix => replacement(s)
     */
    private function getPathRewriteRules(string $type): array
    {
        switch ($type) {
            case 'ondeck':
                // owners must be array: owners.X → owners.0.X
                return [
                    'owners.' => 'owners.0.',
                ];

            case 'credibly':
                // principals and business_location.address must be arrays
                return [
                    'business_location.address.' => 'business_location.address.0.',
                    'principals.'                => 'principals.0.',
                ];

            case 'fox_partner':
                // Everything under merchant wrapper.
                // Owner address sub-fields → merchant.owners.0.address.{field}
                // Business address sub-fields → merchant.businessAddress.{field}
                return [
                    'owners.address'  => 'merchant.owners.0.address.address',
                    'owners.city'     => 'merchant.owners.0.address.city',
                    'owners.state'    => 'merchant.owners.0.address.state',
                    'owners.'         => 'merchant.owners.0.',
                    'business.address'=> 'merchant.businessAddress.address',
                    'business.city'   => 'merchant.businessAddress.city',
                    'business.state'  => 'merchant.businessAddress.state',
                    'business.'       => 'merchant.',
                ];

            case 'forward_financing':
                // owner → lead.contacts_attributes[0], with address sub-object
                // business → lead.account_attributes, with address sub-object
                return [
                    'owner.street1'                  => 'lead.contacts_attributes.0.current_address_attributes.street1',
                    'owner.city'                     => 'lead.contacts_attributes.0.current_address_attributes.city',
                    'owner.state'                    => 'lead.contacts_attributes.0.current_address_attributes.state',
                    'owner.zip'                      => 'lead.contacts_attributes.0.current_address_attributes.zip',
                    'owner.owner_1_percent_ownership' => 'lead.application_attributes.owner_1_percent_ownership',
                    'owner.'                         => 'lead.contacts_attributes.0.',
                    'business.street1'               => 'lead.account_attributes.current_address_attributes.street1',
                    'business.city'                  => 'lead.account_attributes.current_address_attributes.city',
                    'business.state'                 => 'lead.account_attributes.current_address_attributes.state',
                    'business.zip'                   => 'lead.account_attributes.current_address_attributes.zip',
                    'business.'                      => 'lead.account_attributes.',
                ];

            case 'cancapital':
                // Flat fields → nested contactDetails / accountDetails / loanDetails
                return [
                    'firstName'           => 'contactDetails.firstName',
                    'lastName'            => 'contactDetails.lastName',
                    'email'               => 'contactDetails.email',
                    'phone'               => ['contactDetails.phone', 'accountDetails.phone'],
                    'birthDate'           => 'contactDetails.birthDate',
                    'socialSecurityNumber' => 'contactDetails.socialSecurityNumber',
                    'mailingStreet'       => 'contactDetails.mailingStreet',
                    'mailingCity'         => 'contactDetails.mailingCity',
                    'mailingState'        => 'contactDetails.mailingState',
                    'mailingPostalCode'   => 'contactDetails.mailingPostalCode',
                    'name'                => ['accountDetails.name', 'accountDetails.dba'],
                    'billingStreet'       => 'accountDetails.billingStreet',
                    'billingCity'         => 'accountDetails.billingCity',
                    'billingState'        => ['accountDetails.billingState', 'accountDetails.stateOfFormation'],
                    'billingPostalCode'   => 'accountDetails.billingPostalCode',
                    'taxId'               => 'accountDetails.taxId',
                    'bizStartDate'        => 'accountDetails.bizStartDate',
                    'loanAmount'          => 'loanDetails.loanAmount',
                ];

            default:
                return [];
        }
    }

    /**
     * Apply rewrite rules to a single path.
     *
     * @return string|string[]  rewritten path(s)
     */
    private function applyRewriteRules(string $path, array $rules): string|array
    {
        // Sort by longest key first for specificity
        uksort($rules, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($rules as $prefix => $replacement) {
            // Exact match
            if ($path === $prefix) {
                return $replacement;
            }
            // Prefix match (rule must end with '.' or be shorter than path)
            if (str_starts_with($path, $prefix)) {
                $rest = substr($path, strlen($prefix));
                if (is_array($replacement)) {
                    return array_map(fn ($r) => $r . $rest, $replacement);
                }
                return $replacement . $rest;
            }
        }
        return $path;
    }

    /**
     * Add static/literal fields required by specific lender APIs.
     * Uses the "=value" prefix convention that buildPayload() understands.
     */
    private function addLegacyStaticFields(string $type, array &$mapping, Lender $config): void
    {
        switch ($type) {
            case 'ondeck':
                // OnDeck requires externalCustomerId = lead ID (system column 'id')
                $mapping['id'] = 'externalCustomerId';
                break;

            case 'credibly':
                $mapping['=ach']    = 'application_info.product_requested';
                $mapping['=123456'] = 'business_overview.naics';
                break;

            case 'fox_partner':
                // merchantId = lead_id (injected from leadData via 'id' system column)
                $mapping['id'] = 'merchant.merchantId';
                break;

            case 'cancapital':
                $mapping['=CEO']               = 'contactDetails.title';
                $mapping['=US']                = ['contactDetails.mailingCountry', 'accountDetails.billingCountry'];
                $mapping['=Corporation']        = 'accountDetails.businessStructureName';
                $mapping['=Business Services']  = 'accountDetails.industry';
                if (!empty($config->partner_api_key)) {
                    $mapping["={$config->partner_api_key}"] = 'partnerDetails.partnerAPIKey';
                }
                if (!empty($config->sales_rep_email)) {
                    $mapping["={$config->sales_rep_email}"] = 'partnerDetails.partnerEmail';
                }
                break;
        }
    }

    /**
     * When first_name and last_name both target the same lender path, replace
     * them with full_name (already present in leadData from resolveLeadData).
     */
    private function consolidateNamePaths(array &$mapping): void
    {
        if (!isset($mapping['first_name']) || !isset($mapping['last_name'])) {
            return;
        }

        $firstPaths = (array) $mapping['first_name'];
        $lastPaths  = (array) $mapping['last_name'];
        $shared     = array_intersect($firstPaths, $lastPaths);

        if (empty($shared)) {
            return;
        }

        // Add shared path(s) under full_name
        $existing = isset($mapping['full_name']) ? (array) $mapping['full_name'] : [];
        $merged   = array_unique(array_merge($existing, array_values($shared)));
        $mapping['full_name'] = count($merged) === 1 ? reset($merged) : $merged;

        // Remove shared path(s) from first_name and last_name
        $firstOnly = array_values(array_diff($firstPaths, $shared));
        $lastOnly  = array_values(array_diff($lastPaths, $shared));

        if (empty($firstOnly)) {
            unset($mapping['first_name']);
        } else {
            $mapping['first_name'] = count($firstOnly) === 1 ? $firstOnly[0] : $firstOnly;
        }

        if (empty($lastOnly)) {
            unset($mapping['last_name']);
        } else {
            $mapping['last_name'] = count($lastOnly) === 1 ? $lastOnly[0] : $lastOnly;
        }
    }
}
