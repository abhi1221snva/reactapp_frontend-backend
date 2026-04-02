<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Model\Client\ApiLog;
use App\Model\Client\CrmLabel;
use App\Model\Client\CrmLenderApiLabels;
use App\Model\Client\Lender;
use App\Model\Client\CrmLeadLenderApi;
use App\Model\Client\Documents;
use App\Model\Client\EmailSetting;
use App\Model\Client\Fcs;
use App\Model\Client\FcsLenderList;
use App\Model\Client\Lead;
use App\Model\Client\Notification;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemSetting;
use App\Model\Master\AreaCodeList;
use App\Model\User;
use App\Services\ErrorParserService;
use App\Services\FixSuggestionService;
use App\Services\MailService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SendLeadByLenderApi
 *
 * Submits a CRM lead to one or more lender APIs.
 *
 * Improvements over the legacy version:
 *   - Laravel Http facade (30s timeout, 2 retries) instead of raw cURL
 *   - Labels preloaded once per job (eliminates N+1 queries)
 *   - Duplicate detection: skips lenders already successfully submitted
 *   - Structured error parsing via ErrorParserService + FixSuggestionService
 *   - Per-client logging to crm_lender_api_logs (+ legacy ApiLog kept)
 *   - No debug echo/print_r statements
 *   - try/catch throughout — one lender failing never kills the others
 *   - OAuth2 tokens cached per job execution
 *   - Environment-aware upload paths centralised
 */
class SendLeadByLenderApi extends Job
{
    // ── Job config ─────────────────────────────────────────────────────────────
    private const TIMEOUT_SECONDS = 60; // OnDeck takes ~28s to respond
    private const MAX_RETRIES     = 2;
    private const RETRY_DELAY_MS  = 1500;

    // ── Constructor state ──────────────────────────────────────────────────────
    private int    $clientId;
    private array  $data;
    private string $emailType;

    // ── Shared data (loaded once per handle() call) ────────────────────────────
    /** @var array<string, \App\Model\Client\CrmLabel>  keyed by label_title_url */
    private array $labelsByUrl   = [];
    /** @var array<int, array<\App\Model\Client\CrmLenderApiLabels>>  keyed by crm_label_id */
    private array $labelMappings = [];
    /** @var \Illuminate\Database\Eloquent\Collection */
    private $documents;
    private string $uploadsRoot  = '';

    /** @var array<string, string>  OAuth2 token cache: key → token */
    private static array $tokenCache = [];

    // ── Constructor ────────────────────────────────────────────────────────────

    public function __construct(int $clientId, array $data, $emailType)
    {
        $this->clientId  = $clientId;
        $this->data      = $data;
        $this->emailType = $emailType;

        Log::info("SendLeadByLenderApi: initialised", [
            'client_id' => $clientId,
            'lead_id'   => $data['lead_id'] ?? null,
            'lenders'   => count($data['lender_id'] ?? []),
        ]);
    }

    // ── Entry point ────────────────────────────────────────────────────────────

    public function handle(): void
    {
        $leadId    = $this->data['lead_id']    ?? null;
        $lenderIds = $this->data['lender_id']  ?? [];
        $lenderNames = $this->data['lender_name'] ?? [];
        $userId    = (int) ($this->data['user_id'] ?? 0);

        if (!$leadId || empty($lenderIds)) {
            Log::error("SendLeadByLenderApi: missing lead_id or lender_id", $this->data);
            return;
        }

        // ── Load lead ──────────────────────────────────────────────────────────
        $lead = Lead::on("mysql_{$this->clientId}")->find($leadId);
        if (!$lead) {
            Log::error("SendLeadByLenderApi: lead not found", ['lead_id' => $leadId]);
            return;
        }

        // ── Preload labels and documents ONCE for this job ────────────────────
        $this->preloadLabels();
        $this->preloadDocuments((int) $leadId);
        $this->resolveUploadsRoot();

        // ── Build flat EAV lead data and flattened label array ────────────────
        $flatLeadData  = $this->buildFlatLeadData($lead);
        $arrLabels     = $this->flattenLeadData($flatLeadData);

        // ── Track seen credentials to prevent duplicate submissions ───────────
        $seenCredentials = [];

        foreach ($lenderIds as $idx => $lenderEntry) {
            $lenderId   = $lenderEntry['lender_id'] ?? null;
            $lenderName = $lenderNames[$idx]['lender_name'] ?? 'Unknown';

            if (!$lenderId) {
                Log::warning("SendLeadByLenderApi: missing lender_id at index $idx");
                continue;
            }

            try {
                $apiConfig = Lender::on("mysql_{$this->clientId}")
                    ->where('id', $lenderId)
                    ->first();

                if (!$apiConfig) {
                    Log::warning("SendLeadByLenderApi: no API config for lender $lenderId");
                    continue;
                }

                // ── Deduplicate by credentials ─────────────────────────────────
                $credKey = $this->credentialKey($apiConfig);
                if ($credKey !== null && isset($seenCredentials[$credKey])) {
                    Log::info("SendLeadByLenderApi: skipping duplicate credentials", [
                        'lender_id' => $lenderId, 'type' => $apiConfig->type,
                    ]);
                    continue;
                }
                if ($credKey !== null) {
                    $seenCredentials[$credKey] = true;
                }

                // ── Duplicate submission detection ────────────────────────────
                $existing = $this->findSuccessfulSubmission((int) $leadId, (int) $lenderId);
                if ($existing) {
                    Log::info("SendLeadByLenderApi: already submitted", [
                        'lead_id' => $leadId, 'lender_id' => $lenderId,
                        'reference_id' => $existing['reference_id'],
                    ]);
                    $this->notify($userId, (int) $leadId,
                        "Lender <b>$lenderName</b>: Already submitted (ref: {$existing['reference_id']})");
                    continue;
                }

                // ── Dispatch to the correct lender ────────────────────────────
                $result = $this->dispatchToLender(
                    $apiConfig, $lenderName, (int) $lenderId, (int) $leadId,
                    $userId, $flatLeadData, $arrLabels
                );

                // ── Notify + email ────────────────────────────────────────────
                $this->notify($userId, (int) $leadId, $result['notification'] ?? $lenderName);
                $this->sendEmail($result['notification'] ?? '', $userId, (int) $leadId,
                    (string) $this->clientId, $lenderName);

            } catch (\Throwable $e) {
                Log::error("SendLeadByLenderApi: unhandled exception for lender $lenderId", [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }
    }

    // ── Lender dispatcher ──────────────────────────────────────────────────────

    private function dispatchToLender(
        Lender $config,
        string        $lenderName,
        int           $lenderId,
        int           $leadId,
        int           $userId,
        array         $flatLeadData,
        array         $arrLabels
    ): array {
        $type = strtolower($config->type ?? '');

        $methods = [
            'ondeck'            => 'submitOnDeck',
            'credibly'          => 'submitCredibly',
            'bitty_advance'     => 'submitBittyAdvance',
            'fox_partner'       => 'submitFoxPartner',
            'lendini'           => 'submitLendini',
            'specialty'         => 'submitSpecialty',
            'forward_financing' => 'submitForwardFinancing',
            'cancapital'        => 'submitCanCapital',
            'biz2credit'        => 'submitBiz2Credit',
        ];

        if (!isset($methods[$type])) {
            Log::warning("SendLeadByLenderApi: unsupported lender type '$type'");
            return ['notification' => "Lender <b>$lenderName</b>: unsupported type '$type'"];
        }

        return $this->{$methods[$type]}(
            $config, $lenderName, $lenderId, $leadId, $userId, $flatLeadData, $arrLabels
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ── Per-lender implementations ────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════

    // ── OnDeck ─────────────────────────────────────────────────────────────────

    private function submitOnDeck(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped = $this->mapForLender('ondeck_label', $arrLabels);

        $leadLenderRecord = CrmLeadLenderApi::on("mysql_{$this->clientId}")
            ->where('lead_id', $leadId)
            ->where('lender_id', $lenderId)
            ->first();

        $data = [
            'business' => [
                'address' => [
                    'state'        => $mapped['business.address.state']        ?? '',
                    'city'         => $mapped['business.address.city']         ?? '',
                    'zipCode'      => $mapped['business.address.zipCode']      ?? '',
                    'addressLine1' => $mapped['business.address']              ?? '',
                ],
                'phone'                  => $mapped['business.phone']                  ?? '',
                'businessInceptionDate'  => $mapped['business.businessInceptionDate']  ?? '',
                'taxID'                  => preg_replace('/\D/', '', $mapped['business.taxID'] ?? ''),
                'name'                   => $mapped['business.name']                   ?? '',
            ],
            'owners' => [[
                'dateOfBirth' => $mapped['owners.dateOfBirth'] ?? '',
                'homeAddress' => [
                    'state'        => $mapped['owners.homeAddress.state']        ?? '',
                    'city'         => $mapped['owners.homeAddress.city']         ?? '',
                    'zipCode'      => $mapped['owners.homeAddress.zipCode']      ?? '',
                    'addressLine1' => $mapped['owners.homeAddress.address']      ?? '',
                ],
                'email'               => $mapped['owners.email']               ?? '',
                'homePhone'           => $mapped['owners.homePhone']           ?? '',
                'ownershipPercentage' => $mapped['owners.ownershipPercentage'] ?? '',
                'ssn'                 => preg_replace('/\D/', '', $mapped['owners.ssn'] ?? ''),
                'name'                => $mapped['owners.name']                ?? '',
            ]],
            'selfReported' => [
                'revenue'        => $mapped['selfReported.revenue']        ?? '',
                'averageBalance' => $mapped['selfReported.averageBalance'] ?? '',
            ],
        ];

        $auth = base64_encode("{$config->api_username}:{$config->api_password}");
        $headers = [
            'Content-Type' => 'application/json',
            'Apikey'        => $config->api_key,
            'Authorization' => 'Basic ' . $auth,
        ];

        if ($leadLenderRecord && $leadLenderRecord->businessID) {
            $url    = rtrim($config->api_url, '/') . '/application/' . $leadLenderRecord->businessID;
            $method = 'PUT';
        } else {
            $url    = rtrim($config->api_url, '/') . '/application';
            $method = 'POST';
        }

        $result   = $this->httpRequest($method, $url, $data, $headers);
        $parsed   = json_decode($result['body'] ?? '', true) ?? [];
        $success  = $result['success'] && isset($parsed['businessID']);
        $businessId = $parsed['businessID'] ?? ($leadLenderRecord->businessID ?? '');

        // ── Structured error analysis ─────────────────────────────────────────
        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        // ── Log ───────────────────────────────────────────────────────────────
        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, $method,
            $data, $result, $businessId);
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            (string) $result['code'], (string) $businessId);

        // ── Update lead-lender record ─────────────────────────────────────────
        if ($businessId) {
            CrmLeadLenderApi::on("mysql_{$this->clientId}")->updateOrCreate(
                ['lead_id' => $leadId, 'lender_id' => $lenderId,
                 'client_id' => $this->clientId, 'lender_api_type' => 'ondeck'],
                ['businessID' => $businessId, 'updated_at' => Carbon::now()]
            );
        }

        // ── Upload documents ──────────────────────────────────────────────────
        if ($businessId && $this->documents->isNotEmpty()) {
            $docUrl = rtrim($config->api_url, '/') . '/application/' . $businessId . '/documents';
            foreach ($this->documents as $doc) {
                $filePath = $this->getFilePath($doc->file_name);
                if (!file_exists($filePath)) {
                    Log::warning("OnDeck: document not found", ['path' => $filePath]);
                    continue;
                }
                try {
                    Http::timeout(60)
                        ->withHeaders([
                            'Authorization' => 'Basic ' . $auth,
                            'Apikey'        => $config->api_key,
                        ])
                        ->attach('file', file_get_contents($filePath), $doc->file_name)
                        ->post($docUrl, ['description' => $doc->document_type]);
                } catch (\Throwable $e) {
                    Log::warning("OnDeck: document upload failed", ['file' => $doc->file_name, 'error' => $e->getMessage()]);
                }
            }
        }

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (OnDeck)"
            : "Lender <b>$lenderName</b>: " . implode(' | ', array_column($parsedErrors, 'message')) . " (OnDeck)";

        return compact('notification', 'success', 'parsedErrors', 'businessId');
    }

    // ── Credibly ───────────────────────────────────────────────────────────────

    private function submitCredibly(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped = $this->mapForLender('credibly_label', $arrLabels);

        // EIN fallback: use SSN if federal_id not set
        if (empty($mapped['business_overview.federal_id'])) {
            $mapped['business_overview.federal_id'] = preg_replace('/\D/', '',
                $mapped['principals.ssn'] ?? '');
        }

        $amountRequested = str_replace(',', '', $mapped['application_info.amount_requested'] ?? '0');

        $filePaths = $this->buildCrediblyDocuments();

        $data = [
            'business_overview' => [
                'dba'                    => $mapped['business_overview.dba']             ?? '',
                'legal_name'             => $mapped['business_overview.legal_name']      ?? '',
                'state_of_incorporation' => $mapped['business_location.address.state']   ?? '',
                'date_established'       => $mapped['business_overview.date_established'] ?? '',
                'naics'                  => 123456,
                'federal_id'             => preg_replace('/\D/', '', $mapped['business_overview.federal_id'] ?? ''),
            ],
            'business_location' => [
                'address' => [[
                    'address'     => $mapped['business_location.address.address']      ?? '',
                    'city'        => $mapped['business_location.address.city']         ?? '',
                    'state'       => $mapped['business_location.address.state']        ?? '',
                    'postal_code' => $mapped['business_location.address.postal_code']  ?? '',
                ]],
            ],
            'business_contact' => [
                'phone' => $mapped['business_contact.phone'] ?? '',
                'email' => $mapped['business_contact.email'] ?? '',
            ],
            'business_profile' => [
                'ownership' => $mapped['business_overview.dba'] ?? '',
            ],
            'principals' => [[
                'name_last'         => $mapped['principals.name_last']              ?? '',
                'name_first'        => $mapped['principals.name_first']             ?? '',
                'percent_ownership' => $mapped['principals.percent_ownership']      ?? '',
                'ssn'               => preg_replace('/\D/', '', $mapped['principals.ssn'] ?? ''),
                'address'           => [
                    'address'     => $mapped['principals.address.address']      ?? '',
                    'city'        => $mapped['principals.address.city']         ?? '',
                    'state'       => $mapped['principals.address.state']        ?? '',
                    'postal_code' => $mapped['principals.address.postal_code']  ?? '',
                ],
                'dob' => $mapped['principals.dob'] ?? '',
            ]],
            'application_info' => [
                'product_requested' => 'ach',
                'amount_requested'  => $amountRequested,
            ],
            'files' => $filePaths,
        ];

        $url    = rtrim($config->api_url, '/') . '/submission-api/submitApplication';
        $result = $this->httpRequest('POST', $url, $data,
            ['Authorization' => 'Bearer ' . $config->api_key, 'Content-Type' => 'application/json']);

        $parsed     = json_decode($result['body'] ?? '', true) ?? [];
        $responseId = $parsed['response_id'] ?? null;
        $success    = $result['success'] && $responseId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, 'POST',
            $data, $result, (string) ($responseId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            $success ? '201' : (string) $result['code'], (string) ($responseId ?? ''));

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (Credibly)"
            : "Lender <b>$lenderName</b>: " . implode(' | ', array_column($parsedErrors, 'message')) . " (Credibly)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ── BittyAdvance ───────────────────────────────────────────────────────────

    private function submitBittyAdvance(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped = $this->mapForLender('bittyadvance_label', $arrLabels);

        // ── Bank data (FCS) ────────────────────────────────────────────────────
        $fcsData          = Fcs::on("mysql_{$this->clientId}")->where('lead_id', $leadId)->get();
        $recentNegative   = 0;
        $bankId           = null;
        $recentDeposit    = 0;

        if ($fcsData->isNotEmpty()) {
            $last           = $fcsData->last();
            $recentNegative = $last->negatives  ?? 0;
            $bankId         = $last->bank_id;
            $recentDeposit  = $last->deposits   ?? 0;
        }

        $rowCount = 0;
        if ($bankId) {
            $rowCount = FcsLenderList::on("mysql_{$this->clientId}")
                ->where('lead_id', $leadId)->where('bank_id', $bankId)->count();
        }

        // Build deposit buckets (3 months)
        $deposits = ['bank_deposits1' => '0.00', 'bank_deposits2' => '0.00', 'bank_deposits3' => '0.00'];
        foreach ($fcsData->skip(1)->take(3)->values() as $i => $entry) {
            $key = 'bank_deposits' . (3 - $i);
            $deposits[$key] = $entry->deposit ?? '0.00';
        }

        $totalRevenue = 0;
        $revCount     = 0;
        foreach ($fcsData->skip(1) as $entry) {
            $totalRevenue += $entry->revenue ?? 0;
            $revCount++;
        }
        $avgRevenue = $revCount > 0 ? $totalRevenue / $revCount : 0;

        $data = [
            'apikey'              => $config->api_key,
            'development'         => app()->environment('production') ? '0' : '1',
            'leadid'              => $leadId,
            'legal_name'          => $mapped['business.legal_name']   ?? '',
            'address'             => $mapped['business.address']       ?? '',
            'city'                => $mapped['business.city']          ?? '',
            'state'               => $mapped['business.state']         ?? '',
            'zip'                 => $mapped['business.zip']           ?? '',
            'ein'                 => preg_replace('/\D/', '', $mapped['business.ein'] ?? ''),
            'start_date'          => $mapped['business.start_date']    ?? '',
            'owners' => [[
                'first_name'  => $mapped['owners.first_name']  ?? '',
                'last_name'   => $mapped['owners.last_name']   ?? '',
                'address'     => $mapped['owners.address']     ?? '',
                'city'        => $mapped['owners.city']        ?? '',
                'state'       => $mapped['owners.state']       ?? '',
                'zip'         => $mapped['owners.zip']         ?? '',
                'email'       => $mapped['owners.email']       ?? '',
                'cell_phone'  => $mapped['owners.cell_phone']  ?? '',
                'dob'         => $mapped['owners.dob']         ?? '',
                'ssn'         => preg_replace('/\D/', '', $mapped['owners.ssn'] ?? ''),
            ]],
            'requested_amount'    => $mapped['requested_amount']  ?? '',
            'bankruptcy_current'  => 0,
            'advance_default'     => 0,
            'recent_negative_days'=> $recentNegative,
            'advance_current'     => $rowCount,
            'advance_freq1'       => '2',
            'advance_freq2'       => '2',
            'bank_deposits1'      => $deposits['bank_deposits1'],
            'bank_deposits2'      => $deposits['bank_deposits2'],
            'bank_deposits3'      => $deposits['bank_deposits3'],
            'average_revenue'     => $avgRevenue,
        ];

        // Add FCS lender/payment entries
        if ($bankId) {
            foreach (FcsLenderList::on("mysql_{$this->clientId}")
                ->where('lead_id', $leadId)->where('bank_id', $bankId)->get() as $i => $row) {
                $data['advance_provider' . ($i + 1)] = $row->lender_name;
                $data['advance_payment'  . ($i + 1)] = $row->weekly;
            }
        }

        $url    = 'https://dev.bittyadvance.com/api/submit';
        $result = $this->httpRequest('POST', $url, $data,
            ['Accept' => 'application/json', 'Content-Type' => 'application/json']);

        $parsed     = json_decode($result['body'] ?? '', true) ?? [];
        $submissionId = $parsed['id'] ?? null;
        $success    = $result['success'] && $submissionId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, 'POST',
            $data, $result, (string) ($submissionId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            $success ? '200' : '401', (string) ($submissionId ?? ''));

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (BittyAdvance)"
            : "Lender <b>$lenderName</b>: " . implode(' | ', array_column($parsedErrors, 'message')) . " (BittyAdvance)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ── Fox Partner ────────────────────────────────────────────────────────────

    private function submitFoxPartner(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped = $this->mapForLender('fox_partner_label', $arrLabels);

        $filePaths = [];
        foreach ($this->documents as $i => $doc) {
            $filePaths[$i] = [
                'name'   => $doc->file_name,
                'base64' => base64_encode($this->getFilePath($doc->file_name)),
            ];
        }

        $data = [
            'merchant' => [
                'merchantId'        => $leadId,
                'legalName'         => $mapped['business.legalName']      ?? '',
                'ein'               => preg_replace('/\D/', '', $mapped['business.ein'] ?? ''),
                'dba'               => $mapped['business.dba'] ?? ($mapped['business.legalName'] ?? ''),
                'businessStartDate' => $mapped['business.businessStartDate'] ?? '',
                'businessAddress'   => [
                    'address' => $mapped['business.address'] ?? '',
                    'city'    => $mapped['business.city']    ?? '',
                    'state'   => $mapped['business.state']   ?? '',
                ],
                'owners' => [[
                    'firstName' => $mapped['owners.firstName'] ?? '',
                    'lastName'  => $mapped['owners.lastName']  ?? '',
                    'email'     => $mapped['owners.email']     ?? '',
                    'dob'       => $mapped['owners.dob']       ?? '',
                    'ssn'       => preg_replace('/\D/', '', $mapped['owners.ssn'] ?? ''),
                    'address'   => [
                        'address' => $mapped['owners.address'] ?? '',
                        'city'    => $mapped['owners.city']    ?? '',
                        'state'   => $mapped['owners.state']   ?? '',
                    ],
                ]],
            ],
            'documents' => $filePaths,
            'salesRepEmailAddress' => $config->sales_rep_email ?? '',
            'alertEmailAddresses'  => [],
        ];

        // ── OAuth2 token ───────────────────────────────────────────────────────
        $tokenUrl = 'https://identity.funderone.io/connect/token';
        $token    = $this->fetchClientCredentialsToken($tokenUrl,
            $config->api_username ?? '', $config->api_password ?? '');

        if (!$token) {
            Log::error("FoxPartner: could not obtain OAuth2 token");
            return ['notification' => "Lender <b>$lenderName</b>: Authentication failed (Fox Partner)", 'success' => false];
        }

        $url    = rtrim($config->api_url, '/') . '/submissions';
        $result = $this->httpRequest('POST', $url, $data,
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']);

        $parsed       = json_decode($result['body'] ?? '', true) ?? [];
        $submissionId = $parsed['submissionId'] ?? null;
        $success      = $result['success'] && $submissionId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, 'POST',
            $data, $result, (string) ($submissionId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            $success ? '200' : '401', (string) ($submissionId ?? ''));

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (Fox Partner)"
            : "Lender <b>$lenderName</b>: " . implode(' | ', array_column($parsedErrors, 'message')) . " (Fox Partner)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ── Lendini ────────────────────────────────────────────────────────────────

    private function submitLendini(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped       = $this->mapForLender('lendini_label', $arrLabels);
        $docTypes     = ['Application', 'Bank Statement', 'Check', 'Drivers License',
                         'Identification', 'Tax Document', 'Tax Return'];
        $filePaths    = [];

        foreach ($this->documents as $i => $doc) {
            if ($doc->document_type === 'signature_application') continue;
            $filePaths[] = [
                'Type'        => $this->matchDocumentType($doc->document_type, $docTypes, 'Application'),
                'Name'        => $doc->file_name,
                'ContentType' => 'application/pdf',
                'Body'        => rtrim(strtr(base64_encode($this->getFilePath($doc->file_name)), '+/', '-_'), '='),
            ];
        }

        $data = [
            'companyLegalName'      => $mapped['companyLegalName']      ?? '',
            'companyEIN'            => preg_replace('/\D/', '', $mapped['companyEIN'] ?? ''),
            'companyInceptionDate'  => $mapped['companyInceptionDate']  ?? '',
            'companyStreet'         => $mapped['companyStreet']         ?? '',
            'companyCity'           => $mapped['companyCity']           ?? '',
            'companyState'          => $mapped['companyState']          ?? '',
            'companyPostalCode'     => $mapped['companyPostalCode']     ?? '',
            'companyPhone'          => $mapped['companyPhone']          ?? '',
            'ownerFirstName'        => $mapped['ownerFirstName']        ?? '',
            'ownerLastName'         => $mapped['ownerLastName']         ?? '',
            'ownerStreet'           => $mapped['ownerStreet']           ?? '',
            'ownerCity'             => $mapped['ownerCity']             ?? '',
            'ownerState'            => $mapped['ownerState']            ?? '',
            'ownerPostalCode'       => $mapped['ownerPostalCode']       ?? '',
            'ownerBirthdate'        => $mapped['ownerBirthdate']        ?? '',
            'ownerSsn'              => preg_replace('/\D/', '', $mapped['ownerSsn'] ?? ''),
            'ownerEmail'            => $mapped['ownerEmail']            ?? '',
            'ownerMobilePhone'      => $mapped['ownerMobilePhone']      ?? '',
            'ownerPercentOwnership' => $mapped['ownerPercentOwnership'] ?? '',
            'documents'             => $filePaths,
        ];

        $url    = rtrim($config->api_url, '/') . '/postNewDeal';
        $result = $this->httpRequest('POST', $url, $data, [
            'Content-Type' => 'application/json',
            'token-v'      => $config->api_key,
        ]);

        $parsed       = json_decode($result['body'] ?? '', true) ?? [];
        $submissionId = $parsed['id'] ?? null;
        $success      = $result['success'] && $submissionId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, 'POST',
            $data, $result, (string) ($submissionId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            $success ? '200' : '401', (string) ($submissionId ?? ''));

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (Lendini)"
            : "Lender <b>$lenderName</b>: " . implode(' | ', array_column($parsedErrors, 'message')) . " (Lendini)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ── Specialty ──────────────────────────────────────────────────────────────

    private function submitSpecialty(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped   = $this->mapForLender('specialty_label', $arrLabels);
        $docTypes = ['Signed Application', 'Processing Statements', 'Bank Statements',
                     'Drivers License', 'Voided Check', 'Business Lease/ Business Mortgage',
                     'Business License', 'Balance Letter', 'Financials', 'Trade Reference',
                     'Proof of Majority Ownership', 'Misc License', 'Signed Contract',
                     'UCCs', 'External Signed Doc', 'External Unsigned Doc', 'Calculator', 'Other'];

        $filePaths = [];
        foreach ($this->documents as $i => $doc) {
            $name = preg_replace('/\.pdf$/i', '', $doc->file_name);
            $filePaths[] = [
                'category' => $this->matchDocumentType($doc->document_type, $docTypes, 'Other'),
                'name'     => $name,
                'filename' => $doc->file_name,
                'content'  => rtrim(strtr(base64_encode($this->getFilePath($doc->file_name)), '+/', '-_'), '='),
            ];
        }

        // Build as proper array (was string interpolation in original — injection risk)
        $data = [
            'business' => [
                'name'           => $mapped['business.name']      ?? '',
                'dba'            => $mapped['business.name']      ?? '',
                'address'        => $mapped['business.address']   ?? '',
                'city'           => $mapped['business.city']      ?? '',
                'state'          => $mapped['business.state']     ?? '',
                'zip'            => $mapped['business.zip']       ?? '',
                'telephone'      => $mapped['business.telephone'] ?? '',
                'fein'           => preg_replace('/\D/', '', $mapped['business.fein'] ?? ''),
                'amountRequested'=> $mapped['business.amountRequested'] ?? '',
                'startDate'      => $mapped['business.startDate'] ?? '',
                'owners'         => [[
                    'firstName'           => '',
                    'lastName'            => $mapped['owner.lastName']            ?? '',
                    'socialSecurityNumber'=> preg_replace('/\D/', '', $mapped['owner.socialSecurityNumber'] ?? ''),
                    'dateOfBirth'         => $mapped['owner.dateOfBirth']         ?? '',
                    'address'             => $mapped['owner.address']             ?? '',
                    'city'                => $mapped['owner.city']                ?? '',
                    'state'               => $mapped['owner.state']               ?? '',
                    'zip'                 => $mapped['owner.zip']                 ?? '',
                    'emailAddress'        => $mapped['owner.emailAddress']        ?? '',
                    'ownershipPercentage' => $mapped['owner.ownershipPercentage'] ?? '',
                    'mobilePhone'         => $mapped['owner.mobilePhone']         ?? '',
                ]],
                'documents' => $filePaths,
            ],
        ];

        $url    = rtrim($config->api_url, '/') . '/deal-submission';
        $result = $this->httpRequest('POST', $url, $data,
            ['x-api-key' => $config->api_key, 'Content-Type' => 'application/json']);

        $parsed       = json_decode($result['body'] ?? '', true) ?? [];
        $submissionId = $parsed['id'] ?? null;
        $success      = $result['success'] && $submissionId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, 'POST',
            $data, $result, (string) ($submissionId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            $success ? '200' : '401', (string) ($submissionId ?? ''));

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (Specialty)"
            : "Lender <b>$lenderName</b>: " . ($parsed['message'] ?? 'Submission failed') . " (Specialty)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ── Forward Financing ──────────────────────────────────────────────────────

    private function submitForwardFinancing(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped = $this->mapForLender('forward_financing_label', $arrLabels);

        $data = [
            'lead' => [
                'contacts_attributes' => [[
                    'first_name' => $mapped['owner.first_name'] ?? '',
                    'last_name'  => $mapped['owner.last_name']  ?? '',
                    'email'      => $mapped['owner.email']      ?? '',
                    'born_on'    => $mapped['owner.born_on']    ?? '',
                    'cell_phone' => $mapped['owner.cell_phone'] ?? '',
                    'ssn'        => preg_replace('/\D/', '', $mapped['owner.ssn'] ?? ''),
                    'current_address_attributes' => [
                        'street1' => $mapped['owner.street1'] ?? '',
                        'city'    => $mapped['owner.city']    ?? '',
                        'state'   => $mapped['owner.state']   ?? '',
                        'zip'     => $mapped['owner.zip']     ?? '',
                    ],
                ]],
                'account_attributes' => [
                    'name'       => $mapped['business.legal_name'] ?? '',
                    'legal_name' => $mapped['business.legal_name'] ?? '',
                    'phone'      => $mapped['business.phone']      ?? '',
                    'fein'       => preg_replace('/\D/', '', $mapped['business.fein'] ?? ''),
                    'current_address_attributes' => [
                        'street1' => $mapped['business.street1'] ?? '',
                        'city'    => $mapped['business.city']    ?? '',
                        'state'   => $mapped['business.state']   ?? '',
                        'zip'     => $mapped['business.zip']     ?? '',
                    ],
                ],
                'application_attributes' => [
                    'owner_1_percent_ownership' => $mapped['owner.owner_1_percent_ownership'] ?? '',
                ],
            ],
        ];

        $url    = rtrim($config->api_url, '/') . '/lead';
        $result = $this->httpRequest('POST', $url, $data,
            ['x-api-key' => $config->api_key, 'Content-Type' => 'application/json']);

        $parsed       = json_decode($result['body'] ?? '', true) ?? [];
        $submissionId = $parsed['id'] ?? null;
        $success      = $result['success'] && $submissionId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, 'POST',
            $data, $result, (string) ($submissionId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            $success ? '200' : '401', (string) ($submissionId ?? ''));

        // ── Upload attachments ────────────────────────────────────────────────
        if ($submissionId && $this->documents->isNotEmpty()) {
            $attachUrl = rtrim($config->api_url, '/') . '/attachment';
            foreach ($this->documents as $doc) {
                $attachData = [
                    'attachment_url' => $this->getFilePath($doc->file_name),
                    'filename'       => $doc->file_name,
                    'lead_id'        => $submissionId,
                ];
                try {
                    Http::timeout(self::TIMEOUT_SECONDS)
                        ->withHeaders(['x-api-key' => $config->api_key, 'Content-Type' => 'application/json'])
                        ->post($attachUrl, $attachData);
                } catch (\Throwable $e) {
                    Log::warning("ForwardFinancing: attachment upload failed", [
                        'file' => $doc->file_name, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (Forward Financing)"
            : "Lender <b>$lenderName</b>: " . ($parsed['message'] ?? 'Submission failed') . " (Forward Financing)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ── Can Capital ────────────────────────────────────────────────────────────

    private function submitCanCapital(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        $mapped   = $this->mapForLender('cancapital_label', $arrLabels);
        $docTypes = ['Application', 'Bank Statements', 'Competitor Loan Information',
                     'ID Verification', 'Loan Agreement', 'Month to Date Banks', 'Other',
                     'Proof of Ownership', 'Refinance Agreement', 'Tax Lien Statement',
                     'Tax Return', 'Third Party Release Authorization', 'Voided Check'];

        // ── OAuth2 (password grant) ────────────────────────────────────────────
        $token = $this->fetchPasswordGrantToken(
            $config->auth_url  ?? '',
            $config->api_client_id ?? '',
            $config->api_key   ?? '',
            $config->api_username  ?? '',
            $config->api_password  ?? ''
        );

        if (!$token) {
            Log::error("CanCapital: could not obtain OAuth2 token");
            return ['notification' => "Lender <b>$lenderName</b>: Authentication failed (Can Capital)", 'success' => false];
        }

        $data = [
            'loanDetails'    => ['loanAmount' => $mapped['loanAmount'] ?? ''],
            'partnerDetails' => [
                'partnerAPIKey' => $config->partner_api_key ?? '',
                'partnerEmail'  => $config->sales_rep_email ?? '',
            ],
            'accountDetails' => [
                'name'                  => $mapped['name']             ?? '',
                'phone'                 => $mapped['phone']            ?? '',
                'industry'              => 'Business Services',
                'taxId'                 => preg_replace('/\D/', '', $mapped['taxId'] ?? ''),
                'dba'                   => $mapped['name']             ?? '',
                'businessStructureName' => 'Corporation',
                'stateOfFormation'      => $mapped['billingState']     ?? '',
                'bizStartDate'          => $mapped['bizStartDate']     ?? '',
                'billingStreet'         => $mapped['billingStreet']    ?? '',
                'billingCity'           => $mapped['billingCity']      ?? '',
                'billingState'          => $mapped['billingState']     ?? '',
                'billingPostalCode'     => $mapped['billingPostalCode'] ?? '',
                'billingCountry'        => 'US',
            ],
            'contactDetails' => [
                'title'               => 'CEO',
                'firstName'           => $mapped['firstName']           ?? '',
                'lastName'            => $mapped['lastName']            ?? '',
                'email'               => $mapped['email']               ?? '',
                'phone'               => $mapped['phone']               ?? '',
                'birthDate'           => $mapped['birthDate']           ?? '',
                'socialSecurityNumber'=> preg_replace('/\D/', '', $mapped['socialSecurityNumber'] ?? ''),
                'mailingStreet'       => $mapped['mailingStreet']       ?? '',
                'mailingCity'         => $mapped['mailingCity']         ?? '',
                'mailingState'        => $mapped['mailingState']        ?? '',
                'mailingCountry'      => 'US',
                'mailingPostalCode'   => $mapped['mailingPostalCode']   ?? '',
            ],
        ];

        $url    = rtrim($config->api_url, '/') . '/createapplication';
        $result = $this->httpRequest('POST', $url, $data, [
            'Authorization' => 'OAuth ' . $token,
            'Content-Type'  => 'text/plain',
        ]);

        $parsed     = json_decode($result['body'] ?? '', true) ?? [];
        $submissionId = $parsed[0]['ContactDetails']['Id']    ?? null;
        $appName      = $parsed[0]['ApplicationDetails']['Name'] ?? null;
        $success    = $result['success'] && $submissionId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $url, 'POST',
            $data, $result, (string) ($submissionId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($url, $lenderId, $leadId, $data, $result['body'],
            $success ? '200' : '401', (string) ($submissionId ?? ''));

        // ── Upload documents ───────────────────────────────────────────────────
        if ($appName && $this->documents->isNotEmpty()) {
            $uploadUrl = rtrim($config->api_url, '/') . '/uploaddocs';
            foreach ($this->documents as $doc) {
                $docType  = $this->matchDocumentType($doc->document_type, $docTypes, 'Other');
                $filePath = $this->getFilePath($doc->file_name);
                if (!file_exists($filePath)) continue;
                try {
                    $params = http_build_query([
                        'application'   => $appName,
                        'partnerAPIKey' => $config->partner_api_key ?? '',
                        'partnerEmail'  => $config->sales_rep_email ?? '',
                        'name'          => $doc->file_name,
                        'documentType'  => $docType,
                    ]);
                    Http::timeout(60)
                        ->withHeaders([
                            'Authorization' => 'OAuth ' . $token,
                            'Content-Type'  => 'application/pdf',
                        ])
                        ->withBody(file_get_contents($filePath), 'application/pdf')
                        ->post($uploadUrl . '?' . $params);
                } catch (\Throwable $e) {
                    Log::warning("CanCapital: document upload failed", [
                        'file' => $doc->file_name, 'error' => $e->getMessage(),
                    ]);
                }
            }

            // Process application
            try {
                $processUrl = rtrim($config->api_url, '/') . '/processapplication';
                Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders([
                        'Authorization' => 'OAuth ' . $token,
                        'Content-Type'  => 'text/plain',
                    ])
                    ->post($processUrl, [
                        'application'   => $appName,
                        'consentAccepted' => true,
                        'partnerDetails' => [
                            'partnerAPIKey' => $config->partner_api_key ?? '',
                            'partnerEmail'  => $config->sales_rep_email ?? '',
                        ],
                    ]);
            } catch (\Throwable $e) {
                Log::warning("CanCapital: processapplication failed", ['error' => $e->getMessage()]);
            }
        }

        $notification = $success
            ? "Lender <b>$lenderName</b>: Application submitted successfully (Can Capital)"
            : "Lender <b>$lenderName</b>: " . implode(' | ', array_column($parsedErrors, 'message')) . " (Can Capital)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ── Biz2Credit ─────────────────────────────────────────────────────────────

    private function submitBiz2Credit(
        Lender $config, string $lenderName, int $lenderId,
        int $leadId, int $userId, array $flatLeadData, array $arrLabels
    ): array {
        // NOTE: original payload was test/hardcoded data.
        // Map from lead data using the forward_financing column (as per original).
        $mapped = $this->mapForLender('forward_financing_label', $arrLabels);

        $data = [
            'product_type'               => 'termloan',
            'affiliate_lead_reference_id'=> bin2hex(random_bytes(10)),
            'track_id'                   => 34238,
            'lead_id'                    => $leadId,
            'business_info' => [
                'biz_legal_name'       => $mapped['business.legal_name']      ?? '',
                'dba'                  => $mapped['business.legal_name']      ?? '',
                'biz_phone'            => $mapped['business.phone']           ?? '',
                'biz_tin'              => preg_replace('/\D/', '', $mapped['business.fein'] ?? ''),
                'biz_address' => [
                    'address_line1' => $mapped['business.street1'] ?? '',
                    'city'          => $mapped['business.city']    ?? '',
                    'state'         => $mapped['business.state']   ?? '',
                    'zipcode'       => $mapped['business.zip']     ?? '',
                    'country'       => 'United States',
                ],
                'year_of_establishment' => $mapped['business.start_date'] ?? '',
                'naics_code'            => '448120',
                'is_state_corp'         => false,
                'state_of_incorporation'=> $mapped['business.state'] ?? '',
                'other_funding_option'  => 1,
                'role_in_company'       => 'Owner',
            ],
            'owner_info' => [[
                'email'               => $mapped['owner.email']      ?? '',
                'phone'               => $mapped['owner.cell_phone'] ?? '',
                'first_name'          => $mapped['owner.first_name'] ?? '',
                'last_name'           => $mapped['owner.last_name']  ?? '',
                'tin'                 => preg_replace('/\D/', '', $mapped['owner.ssn'] ?? ''),
                'ownership_percentage'=> 100,
                'date_of_birth'       => $mapped['owner.born_on']    ?? '',
                'address' => [
                    'address_line1' => $mapped['owner.street1'] ?? '',
                    'city'          => $mapped['owner.city']    ?? '',
                    'state'         => $mapped['owner.state']   ?? '',
                    'zipcode'       => $mapped['owner.zip']     ?? '',
                ],
                'credit_consent' => 1,
                'is_corporate'   => 1,
                'biz_legal_name' => $mapped['business.legal_name'] ?? '',
            ]],
            'callback_url' => '',
        ];

        $apiUrl = 'https://partner-integration-stage.b2cdev.com/api/v2/create-application';
        $result = $this->httpRequest('POST', $apiUrl, $data, ['Content-Type' => 'application/json']);

        $parsed     = json_decode($result['body'] ?? '', true) ?? [];
        $caseId     = $parsed['data']['case_id']     ?? null;
        $presignedUrl = $parsed['data']['presigned_url'] ?? null;
        $success    = ($parsed['status'] ?? '') === 'success' && $caseId;

        $parsedErrors = [];
        if (!$success) {
            $parsedErrors = $this->parseErrors($result['code'], $result['body']);
        }

        $logId = $this->writeClientLog($leadId, $lenderId, $userId, $apiUrl, 'POST',
            $data, $result, (string) ($caseId ?? ''));
        if (!$success && $logId) {
            $this->enrichClientLog($logId, $result['code'], $result['body'], $flatLeadData);
        }
        $this->writeLegacyLog($apiUrl, $lenderId, $leadId, $data, $result['body'],
            $success ? '200' : '403', (string) ($caseId ?? ''));

        // ── Upload documents via presigned URL ────────────────────────────────
        if ($presignedUrl && $this->documents->isNotEmpty()) {
            foreach ($this->documents as $doc) {
                $filePath = $this->getFilePath($doc->file_name);
                if (!file_exists($filePath)) continue;
                try {
                    Http::timeout(60)
                        ->withHeaders(['Content-Type' => 'application/zip'])
                        ->withBody(file_get_contents($filePath), 'application/zip')
                        ->put($presignedUrl);
                } catch (\Throwable $e) {
                    Log::warning("Biz2Credit: document upload failed", [
                        'file' => $doc->file_name, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $notification = $success
            ? "Lender <b>$lenderName</b>: " . ($parsed['data']['message'] ?? 'Submitted') . " (Biz2Credit)"
            : "Lender <b>$lenderName</b>: " . ($parsed['data']['message'] ?? 'Submission failed') . " (Biz2Credit)";

        return compact('notification', 'success', 'parsedErrors');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ── HTTP utilities ────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send an HTTP request via Laravel Http with timeout + retry.
     * Returns: ['success' => bool, 'code' => int, 'body' => string]
     */
    private function httpRequest(string $method, string $url, array $payload, array $headers): array
    {
        $method = strtolower($method);
        $start  = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception) {
                    // Never retry connection timeouts — server is hanging, retrying just wastes time
                    if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                        return false;
                    }
                    // Retry only on 5xx server errors, not 4xx client errors
                    if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                        return $exception->response->serverError();
                    }
                    return false;
                }, throw: false)
                ->{$method}($url, $payload);

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::info("LenderApi: $method $url → {$response->status()} ({$durationMs}ms)");

            return [
                'success' => $response->successful(),
                'code'    => $response->status(),
                'body'    => $response->body(),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("LenderApi: connection error to $url", ['error' => $e->getMessage()]);
            return ['success' => false, 'code' => 0, 'body' => null];
        } catch (\Throwable $e) {
            Log::error("LenderApi: unexpected error for $url", ['error' => $e->getMessage()]);
            return ['success' => false, 'code' => 0, 'body' => null];
        }
    }

    // ── OAuth2 helpers ─────────────────────────────────────────────────────────

    /** client_credentials grant (Fox Partner / similar) */
    private function fetchClientCredentialsToken(string $tokenUrl, string $clientId, string $clientSecret): ?string
    {
        $cacheKey = "cc_{$tokenUrl}_{$clientId}";
        if (isset(self::$tokenCache[$cacheKey])) {
            return self::$tokenCache[$cacheKey];
        }

        try {
            $response = Http::timeout(15)->asForm()->post($tokenUrl, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]);

            $token = $response->json('access_token');
            if ($token) {
                self::$tokenCache[$cacheKey] = $token;
                return $token;
            }
        } catch (\Throwable $e) {
            Log::error("OAuth2 client_credentials failed", ['url' => $tokenUrl, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /** password grant (Can Capital / Salesforce-style) */
    private function fetchPasswordGrantToken(
        string $tokenUrl, string $clientId, string $clientSecret,
        string $username,  string $password
    ): ?string {
        $cacheKey = "pw_{$tokenUrl}_{$username}";
        if (isset(self::$tokenCache[$cacheKey])) {
            return self::$tokenCache[$cacheKey];
        }

        try {
            $response = Http::timeout(15)->asForm()->post($tokenUrl, [
                'grant_type'    => 'password',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'username'      => $username,
                'password'      => $password,
            ]);

            $token = $response->json('access_token');
            if ($token) {
                self::$tokenCache[$cacheKey] = $token;
                return $token;
            }
        } catch (\Throwable $e) {
            Log::error("OAuth2 password grant failed", ['url' => $tokenUrl, 'error' => $e->getMessage()]);
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ── Data preloading (eliminates N+1 queries) ──────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════

    private function preloadLabels(): void
    {
        $labels = CrmLabel::on("mysql_{$this->clientId}")->get();
        $this->labelsByUrl = $labels->keyBy('label_title_url')->all();

        $ids = $labels->pluck('id')->all();
        if (empty($ids)) return;

        $mappings = CrmLenderApiLabels::on("mysql_{$this->clientId}")
            ->whereIn('crm_label_id', $ids)->get();

        $this->labelMappings = [];
        foreach ($mappings as $m) {
            $this->labelMappings[$m->crm_label_id][] = $m;
        }
    }

    private function preloadDocuments(int $leadId): void
    {
        $this->documents = Documents::on("mysql_{$this->clientId}")
            ->where('lead_id', $leadId)->get();
    }

    private function resolveUploadsRoot(): void
    {
        if (app()->environment('local')) {
            $this->uploadsRoot = '/var/www/html/branch/frontend_beta/public/uploads/';
            return;
        }

        $setting = SystemSetting::on("mysql_{$this->clientId}")->first();
        $this->uploadsRoot = $setting
            ? rtrim($setting->company_domain, '/') . '/uploads/'
            : '/var/www/html/branch/frontend_beta/public/uploads/';
    }

    // ── Build flat EAV data from lead ──────────────────────────────────────────

    private function buildFlatLeadData(Lead $lead): array
    {
        $flat = [];
        foreach ($this->labelsByUrl as $url => $label) {
            $col   = $label->column_name;
            $type  = $label->data_type;
            $value = $lead->{$col} ?? null;

            if ($type === 'phone_number') {
                $flat[$url] = str_replace(['(', ')', '_', '-', ' '], '', (string) $value);
            } elseif ($type === 'date') {
                $flat[$url] = $this->formatDate($value, $col);
            } elseif ($type === 'select_state' || $col === 'state') {
                $flat[$url] = AreaCodeList::on('master')
                    ->where(function ($q) use ($value) {
                        $q->where('state_name', $value)->orWhere('state_code', $value);
                    })->value('state_code') ?? $value;
            } else {
                $flat[$url] = $value;
            }
        }
        return $flat;
    }

    /** Flatten nested lead data (mirrors the original closure) */
    private function flattenLeadData(array $data, string $parentKey = ''): array
    {
        $items = [];
        foreach ($data as $key => $value) {
            $newKey = $parentKey ? "$parentKey.$key" : $key;
            if (is_array($value)) {
                $items = array_merge($items, $this->flattenLeadData($value, $newKey));
            } else {
                $items[] = [$newKey => $value];
            }
        }
        return $items;
    }

    /**
     * Map flattened label data to the lender-specific key column (e.g. ondeck_label).
     * Uses preloaded data — no per-row DB queries.
     *
     * @return array<string, string>  lender_field_path => value
     */
    private function mapForLender(string $lenderColumn, array $arrLabels): array
    {
        $result = [];

        foreach ($arrLabels as $entry) {
            foreach ($entry as $labelTitleUrl => $value) {
                /** @var \App\Model\Client\CrmLabel|null $crmLabel */
                $crmLabel = $this->labelsByUrl[$labelTitleUrl] ?? null;
                if (!$crmLabel) continue;

                $mappings = $this->labelMappings[$crmLabel->id] ?? [];
                foreach ($mappings as $mapping) {
                    $lenderKey = $mapping->{$lenderColumn} ?? null;
                    if (!$lenderKey) continue;

                    if (isset($result[$lenderKey])) {
                        $result[$lenderKey] .= ' ' . $value;
                    } else {
                        $result[$lenderKey] = $value;
                    }
                }
            }
        }

        return $result;
    }

    // ── Document helpers ───────────────────────────────────────────────────────

    private function getFilePath(string $fileName): string
    {
        return rtrim($this->uploadsRoot, '/') . '/' . ltrim($fileName, '/');
    }

    private function buildCrediblyDocuments(): array
    {
        $crediblyTypes = [
            'Bank Statements', 'Signed Application', "Driver's License", 'Voided Check',
            'Business Lease/Business Mortgage', 'Landlord Contact Info', 'Most Recent Tax Return',
            'Credit Card Processing Statements', 'Payoff Letter for Current Funding',
            'Trade Reference', 'Bank Verification',
            'Lien/Judgment payment plan or satisfaction letter', 'Misc License',
            'Business License', 'Trade License', 'Zero Balance Letter',
            'Multiple Location Agreement', 'Proof of Majority Ownership',
            'Bill of Sale for Business Purchase', 'Seller Contact Info',
            'Franchise Contact Info', 'Site Inspection',
            'Levy/Legal Order/Garnishment Documentation',
            'Copy of Social Security Card', 'A/R Aging Report',
            'Proof of U.S. Residency', 'Payment History for Current MCA',
            'UCC Lien Filing', 'Third Party Authorization',
            'Credit Card Processing Termination Letter',
            'Verbal Merchant Lease Agreement', 'Lockbox Documents',
            'Screenshot of New MID and Recent Batching', 'Split Agreement',
            'Correctly Executed Contracts', 'vACH Addendum',
            'Primary Email Address', 'Release of Information',
            'Early Remit Addendum',
            'Assignment and Assumption of Purchase Agreement', 'Signed Contract',
        ];

        $files = [];
        foreach ($this->documents as $i => $doc) {
            $files[$i] = [
                'name' => $doc->file_name,
                'url'  => $this->getFilePath($doc->file_name),
                'type' => $this->matchDocumentType($doc->document_type, $crediblyTypes, 'Signed Application'),
            ];
        }
        return $files;
    }

    // ── Duplicate detection ────────────────────────────────────────────────────

    /**
     * Check crm_lender_api_logs for an existing successful submission.
     * @return array|null  ['reference_id' => string, 'submitted_at' => string] or null
     */
    private function findSuccessfulSubmission(int $leadId, int $lenderId): ?array
    {
        try {
            $conn = "mysql_{$this->clientId}";
            if (!\Illuminate\Support\Facades\Schema::connection($conn)->hasTable('crm_lender_api_logs')) {
                return null;
            }
            $row = DB::connection($conn)->table('crm_lender_api_logs')
                ->where('lead_id',   $leadId)
                ->where('lender_id', $lenderId)
                ->where('status',    'success')
                ->orderByDesc('id')
                ->first();

            if ($row) {
                return [
                    'reference_id' => $row->response_body
                        ? (json_decode($row->response_body, true)['businessID']
                           ?? json_decode($row->response_body, true)['id']
                           ?? 'submitted')
                        : 'submitted',
                    'submitted_at' => $row->created_at,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("findSuccessfulSubmission failed", ['error' => $e->getMessage()]);
        }
        return null;
    }

    // ── Credential deduplication ───────────────────────────────────────────────

    private function credentialKey(Lender $config): ?string
    {
        $type = strtolower($config->type ?? '');
        return match ($type) {
            'ondeck'            => "{$config->api_username}:{$config->api_password}:{$config->api_key}",
            'credibly',
            'bitty_advance',
            'specialty',
            'forward_financing' => $config->api_key,
            'fox_partner',
            'lendini',
            'cancapital'        => "{$config->api_username}:{$config->api_password}",
            default             => null,   // no dedup for unknown types
        };
    }

    // ── Error parsing ──────────────────────────────────────────────────────────

    /**
     * Parse a raw lender API error into structured user-friendly format.
     * @return array<int, array{field: string, message: string, fix: string}>
     */
    private function parseErrors(int $code, ?string $body): array
    {
        try {
            $parser = new ErrorParserService();
            $raw    = $parser->parse($code, $body);
            return array_map(fn ($e) => [
                'field'   => $e['field']    ?? '',
                'message' => $e['message']  ?? $e['raw_message'] ?? '',
                'fix'     => $e['expected'] ?? '',
            ], $raw);
        } catch (\Throwable $e) {
            Log::warning("parseErrors failed", ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    /**
     * Write to crm_lender_api_logs (per-client).
     */
    private function writeClientLog(
        int    $leadId,
        int    $lenderId,
        int    $userId,
        string $url,
        string $method,
        array  $payload,
        array  $result,
        string $referenceId
    ): ?int {
        try {
            $conn = "mysql_{$this->clientId}";
            if (!\Illuminate\Support\Facades\Schema::connection($conn)->hasTable('crm_lender_api_logs')) {
                return null;
            }
            return (int) DB::connection($conn)->table('crm_lender_api_logs')->insertGetId([
                'lead_id'         => $leadId,
                'lender_id'       => $lenderId,
                'user_id'         => $userId,
                'request_url'     => $url,
                'request_method'  => strtoupper($method),
                'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'response_code'   => $result['code'] ?? 0,
                'response_body'   => $result['body'],
                'status'          => ($result['success'] ?? false) ? 'success' : 'http_error',
                'error_message'   => ($result['success'] ?? false) ? null : ("HTTP " . ($result['code'] ?? 0)),
                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("writeClientLog failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Enrich a log entry with structured error analysis and fix suggestions.
     */
    private function enrichClientLog(int $logId, int $code, ?string $body, array $leadData): void
    {
        try {
            $parser    = new ErrorParserService();
            $suggester = new FixSuggestionService();

            $parsedErrors    = $parser->parse($code, $body);
            $fixSuggestions  = $suggester->suggest($parsedErrors, $leadData);
            $isFixable       = !empty(array_filter(
                $fixSuggestions, fn ($e) => ($e['fix_type'] ?? 'unknown') !== 'unknown'
            ));

            $conn = "mysql_{$this->clientId}";
            if (!\Illuminate\Support\Facades\Schema::connection($conn)->hasTable('crm_lender_api_logs')) {
                return;
            }
            DB::connection($conn)->table('crm_lender_api_logs')->where('id', $logId)->update([
                'error_json'      => json_encode($parsedErrors,   JSON_UNESCAPED_UNICODE),
                'fix_suggestions' => json_encode($fixSuggestions, JSON_UNESCAPED_UNICODE),
                'is_fixable'      => $isFixable,
                'updated_at'      => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Write to the legacy master ApiLog table for backward compatibility.
     */
    private function writeLegacyLog(
        string $url,
        int    $lenderId,
        int    $leadId,
        $payload,
        ?string $response,
        string $statusCode,
        string $businessId
    ): void {
        try {
            ApiLog::create([
                'endpoint'      => $url,
                'client_id'     => $this->clientId,
                'lender_id'     => $lenderId,
                'lead_id'       => $leadId,
                'request_data'  => is_array($payload) ? json_encode($payload) : (string) $payload,
                'response_data' => $response,
                'status_code'   => $statusCode,
                'request_ip'    => null,      // no HTTP context in queue
                'user_agent'    => 'queue',
                'businessID'    => $businessId,
                'created_at'    => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("writeLegacyLog failed", ['error' => $e->getMessage()]);
        }
    }

    // ── Notification / Email ───────────────────────────────────────────────────

    private function notify(int $userId, int $leadId, string $message): void
    {
        try {
            $notification = new Notification();
            $notification->setConnection("mysql_{$this->clientId}");
            $notification->user_id = $userId;
            $notification->lead_id = $leadId;
            $notification->message = $message;
            $notification->type    = '2';
            $notification->saveOrFail();
        } catch (\Throwable $e) {
            Log::warning("notify failed", ['error' => $e->getMessage()]);
        }
    }

    private function sendEmail(string $response, $userId, int $leadId, string $clientId, string $lenderName): void
    {
        try {
            $user = User::find($userId);
            if (!$user) return;

            $sendTo = !empty($user->email) ? $user->email : env('DEFAULT_EMAIL', '');
            if (!$sendTo) return;

            $smtpSetting = EmailSetting::on("mysql_$clientId")
                ->where('mail_type', 'notification')->first();
            if (!$smtpSetting) return;

            $smtp             = new SmtpSetting();
            $smtp->mail_driver   = 'SMTP';
            $smtp->mail_host     = $smtpSetting->mail_host;
            $smtp->mail_port     = $smtpSetting->mail_port;
            $smtp->mail_username = $smtpSetting->mail_username;
            $smtp->mail_password = $smtpSetting->mail_password;
            $smtp->from_name     = $smtpSetting->sender_name;
            $smtp->from_email    = $smtpSetting->sender_email;
            $smtp->mail_encryption = $smtpSetting->mail_encryption;

            $from = [
                'address' => $smtp->from_email ?: env('DEFAULT_EMAIL'),
                'name'    => $smtp->from_name  ?: env('DEFAULT_NAME'),
            ];

            $mailable = new SystemNotificationMail(
                $from, 'emails.testmail',
                "API Response - $lenderName  Lead ID: $leadId",
                (array) $response
            );

            (new MailService($clientId, $mailable, $smtp))->sendEmail($sendTo);
        } catch (\Throwable $e) {
            Log::warning("sendEmail failed for lender $lenderName", ['error' => $e->getMessage()]);
        }
    }

    // ── Document type matcher ──────────────────────────────────────────────────

    private function matchDocumentType(string $input, array $validTypes, string $default = 'Other'): string
    {
        $normalized = $this->normalizeInput($input);
        return $this->findClosestMatch($normalized, $validTypes) ?? $default;
    }

    // ── Misc helpers ───────────────────────────────────────────────────────────

    private function normalizeInput(string $input): string
    {
        $normalized = strtolower($input);
        $normalized = preg_replace('/[^a-z\s]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }

    private function findClosestMatch(string $input, array $types): ?string
    {
        $closest  = null;
        $shortest = PHP_INT_MAX;
        $words    = explode(' ', $input);

        foreach ($types as $type) {
            $normalizedType = strtolower($type);

            foreach ($words as $word) {
                if ($word && str_contains($normalizedType, $word)) {
                    return $type;  // partial word match wins immediately
                }
            }

            $lev = levenshtein($input, $normalizedType);
            if ($lev === 0) return $type;
            if ($lev < $shortest) {
                $shortest = $lev;
                $closest  = $type;
            }
        }

        return $shortest <= 5 ? $closest : null;
    }

    private function formatDate(?string $dateInput, string $columnName): ?string
    {
        if (!$dateInput) return null;
        try {
            return Carbon::parse($dateInput)->format('Y-m-d');
        } catch (Exception $e) {
            Log::warning("formatDate: could not parse '{$dateInput}' for column '{$columnName}'");
            return null;
        }
    }
}
