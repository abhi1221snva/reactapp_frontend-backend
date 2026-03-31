<?php

namespace App\Services;

use App\Model\Client\LenderApplication;
use App\Model\Client\LenderDocument;
use App\Model\Client\LenderOffer;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OnDeck Partner API v2 Service
 *
 * Handles the full lifecycle of a lead's application with OnDeck:
 *   prequalification → preapproval → application → documents → status → offers → confirm
 *
 * Credentials are resolved per-client from crm_lender_apis (api_name = 'ondeck')
 * with fallback to ONDECK_API_KEY / ONDECK_BASE_URL env variables.
 *
 * All outbound requests are logged to crm_lender_api_logs for audit.
 */
class OnDeckService
{
    const LENDER_NAME   = 'ondeck';
    const DEFAULT_BASE  = 'https://api.ondeck.com';
    const TIMEOUT       = 30;
    const MAX_RETRIES   = 2;

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Submit a new application (prequalification | preapproval | application | lead).
     * Returns the LenderApplication model on success.
     *
     * @throws \RuntimeException on unrecoverable API failure
     */
    public function submitApplication(int $leadId, int $clientId, string $type = 'application'): LenderApplication
    {
        $conn = "mysql_{$clientId}";

        // Guard: prevent duplicate submissions of the same type within 5 minutes
        $existing = DB::connection($conn)
            ->table('lender_applications')
            ->where('lead_id', $leadId)
            ->where('lender_name', self::LENDER_NAME)
            ->where('submission_type', $type)
            ->where('status', '!=', 'pending')
            ->where('created_at', '>', now()->subMinutes(5))
            ->first();

        if ($existing && !empty($existing->business_id)) {
            throw new \RuntimeException(
                "Duplicate submission blocked: a {$type} was already submitted " .
                "{$existing->created_at} (businessID: {$existing->business_id})"
            );
        }

        $endpointMap = [
            'prequalification' => 'POST /prequalification',
            'preapproval'      => 'POST /preapproval',
            'application'      => 'POST /application',
            'lead'             => 'POST /lead',
        ];

        [$method, $path] = explode(' ', $endpointMap[$type] ?? 'POST /application', 2);

        $payload = $this->buildApplicationPayload($leadId, $clientId, $type);
        $result  = $this->request($method, $path, $payload, $leadId, $clientId);

        // Persist application record
        $app = $this->upsertApplication($conn, $leadId, $type, $result['body']);

        return $app;
    }

    /**
     * Update an existing application using the stored businessID.
     */
    public function updateApplication(int $leadId, int $clientId, string $type = 'application'): LenderApplication
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        $pathMap = [
            'prequalification' => "/prequalification/{$app->business_id}",
            'preapproval'      => "/preapproval/{$app->business_id}",
            'application'      => "/application/{$app->business_id}",
            'lead'             => "/lead/{$app->business_id}",
        ];

        $path    = $pathMap[$app->submission_type] ?? "/application/{$app->business_id}";
        $payload = $this->buildApplicationPayload($leadId, $clientId, $app->submission_type);
        $result  = $this->request('PUT', $path, $payload, $leadId, $clientId);

        return $this->upsertApplication($conn, $leadId, $app->submission_type, $result['body'], $app);
    }

    /**
     * Mark merchant as contactable (when initially submitted with contactable=false).
     */
    public function markContactable(int $leadId, int $clientId): array
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        $result = $this->request('PUT', "/application/{$app->business_id}/contactable", [], $leadId, $clientId);
        return $result['body'];
    }

    /**
     * Upload a single document to OnDeck.
     */
    public function uploadDocument(
        int    $leadId,
        int    $clientId,
        string $filePath,
        string $originalName,
        string $documentNeed = ''
    ): LenderDocument {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        // Create DB record first so we can update status
        $doc = DB::connection($conn)->table('lender_documents')->insertGetId([
            'lead_id'       => $leadId,
            'business_id'   => $app->business_id,
            'lender_name'   => self::LENDER_NAME,
            'document_need' => $documentNeed ?: null,
            'file_path'     => $filePath,
            'original_name' => $originalName,
            'upload_status' => 'pending',
            'uploaded_by'   => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $path = "/application/{$app->business_id}/document";
        if ($documentNeed) {
            $path .= '?documentNeed=' . urlencode($documentNeed);
        }

        try {
            $result = $this->requestMultipart('POST', $path, $filePath, $originalName, $leadId, $clientId);

            DB::connection($conn)->table('lender_documents')->where('id', $doc)->update([
                'upload_status'   => 'uploaded',
                'lender_response' => json_encode($result['body']),
                'uploaded_at'     => now(),
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            DB::connection($conn)->table('lender_documents')->where('id', $doc)->update([
                'upload_status' => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at'    => now(),
            ]);
            throw $e;
        }

        $docModel = new LenderDocument();
        $docModel->setConnection($conn);
        return $docModel->newQuery()->find($doc);
    }

    /**
     * Get required documents for the current application from OnDeck.
     */
    public function getRequiredDocuments(int $leadId, int $clientId): array
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        $result = $this->request('GET', "/application/{$app->business_id}/documents/required", [], $leadId, $clientId);
        return $result['body'];
    }

    /**
     * Get current application status from OnDeck.
     * Also syncs the status back into lender_applications.
     */
    public function getStatus(int $leadId, int $clientId): array
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        $result = $this->request('GET', "/application/{$app->business_id}/status", [], $leadId, $clientId);
        $body   = $result['body'];

        // Sync status from OnDeck response
        $stage = strtolower($body['outcomeStatus']['stage'] ?? '');
        $note  = $body['outcomeStatus']['note'] ?? '';

        $statusMap = [
            'pending submission'             => 'pending',
            'underwriting'                   => 'underwriting',
            'approved'                       => 'approved',
            'closing'                        => 'closing',
            'funded'                         => 'funded',
            'declined'                       => 'declined',
            'application incomplete'         => 'incomplete',
            'cannot contact'                 => 'cannot_contact',
            'received attempting to contact' => 'submitted',
            'received - unable to contact'   => 'cannot_contact',
            'closed'                         => 'closed',
        ];

        $mappedStatus = $statusMap[$stage] ?? 'other';

        DB::connection($conn)->table('lender_applications')
            ->where('id', $app->id)
            ->update([
                'status'      => $mappedStatus,
                'status_note' => $note ?: null,
                'updated_at'  => now(),
            ]);

        return $body;
    }

    /**
     * Get all active offers from OnDeck.
     * Syncs offers into lender_offers table.
     */
    public function getOffers(int $leadId, int $clientId): array
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        $result = $this->request('GET', "/application/{$app->business_id}/offer", [], $leadId, $clientId);
        $body   = $result['body'];

        // Sync offers
        if (!empty($body['approved'])) {
            $this->syncOffers($conn, $leadId, $app->business_id, $body);
        }

        return $body;
    }

    /**
     * Calculate pricing for a specific offer configuration.
     */
    public function getPricing(int $leadId, int $clientId, array $params): array
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        $result = $this->request('POST', "/pricing/{$app->business_id}", $params, $leadId, $clientId);

        // Update raw_pricing on the matching offer record
        if (!empty($params['offerId'])) {
            DB::connection($conn)->table('lender_offers')
                ->where('lead_id', $leadId)
                ->where('business_id', $app->business_id)
                ->where('offer_id', $params['offerId'])
                ->update([
                    'raw_pricing'    => json_encode($result['body']),
                    'payment_amount' => $result['body']['payment'] ?? null,
                    'updated_at'     => now(),
                ]);
        }

        return $result['body'];
    }

    /**
     * Confirm (accept) an offer — triggers closing/funding process.
     */
    public function confirmOffer(int $leadId, int $clientId, array $params): array
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId);

        $result = $this->request('POST', "/confirm-offer/{$app->business_id}", $params, $leadId, $clientId);
        $body   = $result['body'];

        // Mark the offer as confirmed
        if (!empty($params['offerId'])) {
            DB::connection($conn)->table('lender_offers')
                ->where('lead_id', $leadId)
                ->where('business_id', $app->business_id)
                ->where('offer_id', $params['offerId'])
                ->update([
                    'status'       => 'confirmed',
                    'confirmed_at' => now(),
                    'updated_at'   => now(),
                ]);

            // Decline all other active offers
            DB::connection($conn)->table('lender_offers')
                ->where('lead_id', $leadId)
                ->where('business_id', $app->business_id)
                ->where('offer_id', '!=', $params['offerId'])
                ->where('status', 'active')
                ->update(['status' => 'declined', 'updated_at' => now()]);
        }

        // Advance application status to closing
        DB::connection($conn)->table('lender_applications')
            ->where('id', $app->id)
            ->update(['status' => 'closing', 'updated_at' => now()]);

        return $body;
    }

    /**
     * Check renewal eligibility for a funded loan.
     */
    public function getRenewalEligibility(int $leadId, int $clientId): array
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId, ['funded', 'closing']);

        $result = $this->request('GET', "/renewal-eligibility/{$app->business_id}", [], $leadId, $clientId);
        return $result['body'];
    }

    /**
     * Submit a renewal request for an eligible funded loan.
     */
    public function submitRenewal(int $leadId, int $clientId): LenderApplication
    {
        $conn = "mysql_{$clientId}";
        $app  = $this->requireApplication($conn, $leadId, ['funded', 'closing']);

        $payload = $this->buildApplicationPayload($leadId, $clientId, 'application');
        $result  = $this->request('POST', "/renewal/source/{$app->business_id}", $payload, $leadId, $clientId);

        // Create a new application record for the renewal
        $conn2 = "mysql_{$clientId}";
        $newId = DB::connection($conn2)->table('lender_applications')->insertGetId([
            'lead_id'         => $leadId,
            'lender_name'     => self::LENDER_NAME,
            'business_id'     => $result['body']['businessID'] ?? $app->business_id,
            'application_number' => $result['body']['applicationNumber'] ?? null,
            'submission_type' => 'application',
            'status'          => 'submitted',
            'raw_response'    => json_encode($result['body']),
            'submitted_by'    => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $appModel = new LenderApplication();
        $appModel->setConnection($conn2);
        return $appModel->newQuery()->find($newId);
    }

    /**
     * Get the stored application record and all related documents/offers/logs for a lead.
     */
    public function getLocalData(int $leadId, int $clientId): array
    {
        $conn = "mysql_{$clientId}";

        $app = DB::connection($conn)
            ->table('lender_applications')
            ->where('lead_id', $leadId)
            ->where('lender_name', self::LENDER_NAME)
            ->orderByDesc('created_at')
            ->first();

        $docs = $app
            ? DB::connection($conn)->table('lender_documents')
                ->where('lead_id', $leadId)
                ->where('business_id', $app->business_id)
                ->orderByDesc('created_at')
                ->get()->toArray()
            : [];

        $offers = $app
            ? DB::connection($conn)->table('lender_offers')
                ->where('lead_id', $leadId)
                ->where('business_id', $app->business_id)
                ->orderBy('loan_amount', 'desc')
                ->get()->toArray()
            : [];

        $logs = DB::connection($conn)
            ->table('crm_lender_api_logs')
            ->where('lead_id', $leadId)
            ->where('request_url', 'like', '%ondeck%')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()->toArray();

        return compact('app', 'docs', 'offers', 'logs');
    }

    // ── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Build the full OnDeck API payload from EAV lead data.
     */
    private function buildApplicationPayload(int $leadId, int $clientId, string $type): array
    {
        $conn  = "mysql_{$clientId}";
        $creds = $this->getCredentials($clientId);

        // System columns from crm_leads
        $lead  = (array) DB::connection($conn)->table('crm_leads')->find($leadId);

        // EAV values from crm_lead_values
        $eav   = DB::connection($conn)
            ->table('crm_lead_values')
            ->where('lead_id', $leadId)
            ->pluck('field_value', 'field_key')
            ->toArray();

        // Merged data (EAV overrides system columns for same key)
        $d = array_merge($lead, $eav);

        // ── Use DB-configured payload_mapping when available ──────────────────
        if (!empty($creds['payloadMapping'])) {
            $payload = [];
            foreach ($creds['payloadMapping'] as $fieldKey => $ondeckPath) {
                $value = $d[$fieldKey] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $paths = is_array($ondeckPath) ? $ondeckPath : [$ondeckPath];
                foreach ($paths as $path) {
                    $this->setNestedValue($payload, $path, $value);
                }
            }
            $payload['externalCustomerId'] = (string)$leadId;

            // Fallback: build owner name from first_name + last_name if not mapped via full_name
            if (empty($payload['owners'][0]['name'])) {
                $name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
                if ($name) {
                    $this->setNestedValue($payload, 'owners.0.name', $name);
                }
            }

            // Apply OnDeck field length constraints
            if (isset($payload['owners'][0]['name'])) {
                $payload['owners'][0]['name'] = substr($payload['owners'][0]['name'], 0, 50);
            }
            if (isset($payload['business']['name'])) {
                $payload['business']['name'] = substr($payload['business']['name'], 0, 100);
            }

            return $payload;
        }

        // ── Fallback: hardcoded field mapping ─────────────────────────────────

        $ownerName = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));

        $businessAddress = [
            'addressLine1' => $d['address'] ?? '',
            'city'         => $d['city']    ?? '',
            'state'        => $d['state']   ?? '',
            'zipCode'      => $d['zip']     ?? $d['zip_code'] ?? '',
        ];

        $ownerAddress = [
            'addressLine1' => $d['home_address'] ?? $d['address'] ?? '',
            'city'         => $d['home_city']    ?? $d['city']    ?? '',
            'state'        => $d['home_state']   ?? $d['state']   ?? '',
            'zipCode'      => $d['home_zip']     ?? $d['zip']     ?? $d['zip_code'] ?? '',
        ];

        // For prequalification and preapproval, relax required field strictness
        $isLight = in_array($type, ['prequalification', 'preapproval', 'lead']);

        $business = array_filter([
            'name'                  => $d['company_name'] ?? '',
            'phone'                 => preg_replace('/\D/', '', $d['phone_number'] ?? ''),
            'businessInceptionDate' => $d['inception_date'] ?? $d['business_inception_date'] ?? null,
            'taxID'                 => preg_replace('/\D/', '', $d['tax_id'] ?? $d['taxid'] ?? ''),
            'address'               => array_filter($businessAddress),
            'legalEntity'           => $d['legal_entity']       ?? null,
            'industryNAICSCode'     => $d['naics_code']         ?? null,
            'industrySICCode'       => $d['sic_code']           ?? null,
            'doingBusinessAs'       => $d['dba']                ?? null,
            'loanPurpose'           => $d['loan_purpose']       ?? null,
            'natureOfBusiness'      => $d['nature_of_business'] ?? null,
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        $owner = array_filter([
            'name'                => $ownerName ?: null,
            'email'               => $d['email']           ?? null,
            'ssn'                 => preg_replace('/\D/', '', $d['ssn'] ?? ''),
            'dateOfBirth'         => $d['date_of_birth']   ?? $d['dob'] ?? null,
            'ownershipPercentage' => isset($d['ownership_percentage']) ? (int)$d['ownership_percentage'] : null,
            'homeAddress'         => array_filter($ownerAddress),
            'homePhone'           => preg_replace('/\D/', '', $d['phone_number'] ?? $d['home_phone'] ?? ''),
            'cellPhoneNumber'     => preg_replace('/\D/', '', $d['cell_phone'] ?? ''),
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        $selfReported = array_filter([
            'revenue'             => isset($d['annual_revenue']) ? (float)$d['annual_revenue'] : null,
            'averageBalance'      => isset($d['average_balance']) ? (float)$d['average_balance'] : null,
            'desiredLoanAmount'   => isset($d['desired_loan_amount']) ? (float)$d['desired_loan_amount'] : null,
            'desiredLoanTerm'     => isset($d['desired_loan_term']) ? (int)$d['desired_loan_term'] : null,
            'mcaBalance'          => isset($d['mca_balance']) ? (float)$d['mca_balance'] : null,
            'averageCCvolume'     => isset($d['avg_cc_volume']) ? (float)$d['avg_cc_volume'] : null,
        ], fn($v) => $v !== null);

        return array_filter([
            'business'           => $business,
            'owners'             => [$owner],
            'selfReported'       => $selfReported ?: (object)[],
            'externalCustomerId' => (string)$leadId,
        ], fn($v) => !empty($v));
    }

    /**
     * Perform an HTTP request to the OnDeck API with retry logic.
     * Logs every attempt to crm_lender_api_logs.
     */
    private function request(
        string $method,
        string $path,
        array  $payload,
        int    $leadId,
        int    $clientId
    ): array {
        $creds    = $this->getCredentials($clientId);
        $url      = $creds['baseUrl'] . '/' . ltrim($path, '/');
        $client   = $this->makeHttpClient($creds['username'], $creds['password'], $creds['extraHeaders']);
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES + 1; $attempt++) {
            $start = microtime(true);
            try {
                $options = [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept'       => 'application/json',
                    ],
                    'timeout' => self::TIMEOUT,
                ];

                if (!empty($payload) && !in_array(strtoupper($method), ['GET', 'HEAD'])) {
                    $options['json'] = $payload;
                }

                $response   = $client->request($method, $url, $options);
                $duration   = (int)((microtime(true) - $start) * 1000);
                $statusCode = $response->getStatusCode();
                $body       = json_decode((string)$response->getBody(), true) ?? [];

                $this->logApiCall($leadId, $clientId, $method, $url, $payload, $statusCode, $body, 'success', null, $duration, $attempt);

                return ['status_code' => $statusCode, 'body' => $body];

            } catch (RequestException $e) {
                $duration   = (int)((microtime(true) - $start) * 1000);
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
                $respBody   = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
                $errStatus  = $statusCode ? 'http_error' : 'error';

                $this->logApiCall($leadId, $clientId, $method, $url, $payload, $statusCode, $respBody, $errStatus, $e->getMessage(), $duration, $attempt);

                $lastError = $e;

                // Do not retry on 4xx client errors
                if ($statusCode >= 400 && $statusCode < 500) {
                    $decoded = json_decode($respBody, true) ?? [];
                    $errMsg  = isset($decoded['errorMessages']) && is_array($decoded['errorMessages'])
                        ? implode('; ', $decoded['errorMessages'])
                        : ($decoded['message'] ?? $decoded['error'] ?? $e->getMessage());
                    throw new \RuntimeException('OnDeck: ' . $errMsg, $statusCode);
                }

            } catch (ConnectException $e) {
                $duration = (int)((microtime(true) - $start) * 1000);
                $this->logApiCall($leadId, $clientId, $method, $url, $payload, 0, '', 'timeout', $e->getMessage(), $duration, $attempt);
                $lastError = $e;
            }

            if ($attempt <= self::MAX_RETRIES) {
                sleep($attempt); // exponential-ish backoff: 1s, 2s
            }
        }

        throw new \RuntimeException('OnDeck API unreachable after ' . (self::MAX_RETRIES + 1) . ' attempts: ' . ($lastError?->getMessage()));
    }

    /**
     * Multipart/form-data request for document upload.
     */
    private function requestMultipart(
        string $method,
        string $url,
        string $filePath,
        string $originalName,
        int    $leadId,
        int    $clientId
    ): array {
        $creds   = $this->getCredentials($clientId);
        $fullUrl = $creds['baseUrl'] . '/' . ltrim($url, '/');
        $client  = $this->makeHttpClient($creds['username'], $creds['password'], $creds['extraHeaders']);
        $start   = microtime(true);

        try {
            $response   = $client->request($method, $fullUrl, [
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => $originalName,
                    ],
                ],
                'timeout' => self::TIMEOUT,
            ]);
            $duration   = (int)((microtime(true) - $start) * 1000);
            $statusCode = $response->getStatusCode();
            $body       = json_decode((string)$response->getBody(), true) ?? [];

            $this->logApiCall($leadId, $clientId, 'POST', $fullUrl, ['file' => $originalName], $statusCode, $body, 'success', null, $duration, 1);
            return ['status_code' => $statusCode, 'body' => $body];

        } catch (RequestException $e) {
            $duration   = (int)((microtime(true) - $start) * 1000);
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $respBody   = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            $this->logApiCall($leadId, $clientId, 'POST', $fullUrl, ['file' => $originalName], $statusCode, $respBody, 'http_error', $e->getMessage(), $duration, 1);
            throw new \RuntimeException('Document upload failed: ' . $e->getMessage(), $statusCode);
        }
    }

    /**
     * Resolve or create a LenderApplication record from an API response.
     */
    private function upsertApplication(
        string           $conn,
        int              $leadId,
        string           $type,
        array            $responseBody,
        ?object          $existing = null
    ): LenderApplication {
        $businessId        = $responseBody['businessID']        ?? null;
        $applicationNumber = $responseBody['applicationNumber'] ?? null;
        $approved          = $responseBody['approved']          ?? false;

        $status = $approved ? 'approved' : 'submitted';

        $data = [
            'business_id'        => $businessId,
            'application_number' => $applicationNumber,
            'status'             => $status,
            'raw_response'       => json_encode($responseBody),
            'updated_at'         => now(),
        ];

        if ($existing) {
            DB::connection($conn)->table('lender_applications')->where('id', $existing->id)->update($data);
            $id = $existing->id;
        } else {
            $id = DB::connection($conn)->table('lender_applications')->insertGetId(array_merge($data, [
                'lead_id'         => $leadId,
                'lender_name'     => self::LENDER_NAME,
                'submission_type' => $type,
                'submitted_by'    => null,
                'created_at'      => now(),
            ]));
        }

        $model = new LenderApplication();
        $model->setConnection($conn);
        return $model->newQuery()->find($id);
    }

    /**
     * Sync offers from an OnDeck response into lender_offers.
     * Marks previously active offers as expired before inserting fresh data.
     */
    private function syncOffers(string $conn, int $leadId, string $businessId, array $body): void
    {
        // Expire old active offers
        DB::connection($conn)->table('lender_offers')
            ->where('lead_id', $leadId)
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $products = array_merge(
            $body['termProducts']          ?? [],
            $body['lineOfCreditProducts']  ?? [],
            $body['products']              ?? [],
        );

        foreach ($products as $product) {
            if (($product['decisionStatus'] ?? '') !== 'approved') continue;
            $productType = str_contains(strtolower($product['name'] ?? ''), 'line') ? 'line_of_credit' : 'term_loan';

            foreach ($product['offers'] ?? [] as $offer) {
                $pricing = $offer['pricingDetails'] ?? [];
                DB::connection($conn)->table('lender_offers')->insert([
                    'lead_id'          => $leadId,
                    'business_id'      => $businessId,
                    'lender_name'      => self::LENDER_NAME,
                    'offer_id'         => $offer['offerId']           ?? null,
                    'product_type'     => $productType,
                    'loan_amount'      => $offer['loanAmount']        ?? null,
                    'term_months'      => $offer['term']              ?? null,
                    'factor_rate'      => $offer['defaultCentsOnDollar'] ?? null,
                    'apr'              => $offer['apr']               ?? $pricing['aprPercentage'] ?? null,
                    'payment_amount'   => $pricing['periodicPayment'] ?? null,
                    'origination_fee'  => null,
                    'total_payback'    => $pricing['totalPayback']    ?? null,
                    'status'           => 'active',
                    'raw_offer'        => json_encode($offer),
                    'raw_pricing'      => json_encode($pricing),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    /**
     * Require an existing LenderApplication or throw a clear exception.
     * Optionally restrict to specific statuses (e.g. only funded loans for renewal).
     */
    private function requireApplication(string $conn, int $leadId, array $allowedStatuses = []): object
    {
        $query = DB::connection($conn)
            ->table('lender_applications')
            ->where('lead_id', $leadId)
            ->where('lender_name', self::LENDER_NAME)
            ->whereNotNull('business_id');

        if ($allowedStatuses) {
            $query->whereIn('status', $allowedStatuses);
        }

        $app = $query->orderByDesc('created_at')->first();

        if (!$app) {
            $msg = $allowedStatuses
                ? 'No OnDeck application found with status: ' . implode('|', $allowedStatuses)
                : 'Submit an application first before performing this action.';
            throw new \RuntimeException($msg, 422);
        }

        return $app;
    }

    /**
     * Resolve OnDeck credentials.
     * Priority: crm_lender_apis (type = 'ondeck' OR api_name LIKE '%ondeck%') → env vars.
     * Returns [baseUrl, username, password].
     */
    /**
     * Resolve OnDeck credentials + config from crm_lender_apis, falling back to env vars.
     * Returns associative array: baseUrl, username, password, extraHeaders, payloadMapping.
     */
    private function getCredentials(int $clientId): array
    {
        $conn   = "mysql_{$clientId}";
        $config = DB::connection($conn)
            ->table('crm_lender_apis')
            ->where(function ($q) {
                $q->whereRaw("LOWER(type) = 'ondeck'")
                  ->orWhereRaw("LOWER(api_name) LIKE '%ondeck%'");
            })
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if ($config) {
            $baseUrl = rtrim($config->base_url ?: $config->url ?: self::DEFAULT_BASE, '/');
            $creds   = is_string($config->auth_credentials)
                ? (json_decode($config->auth_credentials, true) ?? [])
                : (array)($config->auth_credentials ?? []);

            $username = $creds['username'] ?? $config->username ?? '';
            $password = $creds['password'] ?? $config->password ?? '';

            $extraHeaders = [];
            if (!empty($config->default_headers)) {
                $parsed = is_string($config->default_headers)
                    ? (json_decode($config->default_headers, true) ?? [])
                    : (array)$config->default_headers;
                // Strip Content-Type — Guzzle sets it correctly per request
                unset($parsed['Content-Type'], $parsed['content-type']);
                $extraHeaders = $parsed;
            }

            $payloadMapping = [];
            if (!empty($config->payload_mapping)) {
                $payloadMapping = is_string($config->payload_mapping)
                    ? (json_decode($config->payload_mapping, true) ?? [])
                    : (array)$config->payload_mapping;
            }

            if ($username && $password) {
                return compact('baseUrl', 'username', 'password', 'extraHeaders', 'payloadMapping');
            }
        }

        // Fallback to env vars
        $baseUrl  = rtrim(env('ONDECK_BASE_URL', self::DEFAULT_BASE), '/');
        $username = env('ONDECK_USERNAME', '');
        $password = env('ONDECK_PASSWORD', '');

        if (empty($username) || empty($password)) {
            throw new \RuntimeException(
                'OnDeck credentials not configured. Add an OnDeck entry in CRM → Lender API Configs ' .
                'with username and password, or set ONDECK_USERNAME / ONDECK_PASSWORD / ONDECK_BASE_URL in the environment.'
            );
        }

        return ['baseUrl' => $baseUrl, 'username' => $username, 'password' => $password, 'extraHeaders' => [], 'payloadMapping' => []];
    }

    private function makeHttpClient(string $username, string $password, array $extraHeaders = []): HttpClient
    {
        return new HttpClient([
            'auth'    => [$username, $password],
            'headers' => array_merge(['Accept' => 'application/json'], $extraHeaders),
        ]);
    }

    /**
     * Set a value in a nested array using a dot-notation path.
     * Numeric path segments are treated as array indices (e.g. "owners.0.name").
     */
    private function setNestedValue(array &$target, string $path, $value): void
    {
        $parts = explode('.', $path);
        $last  = array_pop($parts);
        $ref   = &$target;

        foreach ($parts as $part) {
            $key = is_numeric($part) ? (int)$part : $part;
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }

        $ref[is_numeric($last) ? (int)$last : $last] = $value;
    }

    /**
     * Log every API call to crm_lender_api_logs for audit and debugging.
     */
    private function logApiCall(
        int    $leadId,
        int    $clientId,
        string $method,
        string $url,
        mixed  $requestPayload,
        int    $statusCode,
        mixed  $responseBody,
        string $status,
        ?string $errorMessage,
        int    $durationMs,
        int    $attempt
    ): void {
        try {
            DB::connection("mysql_{$clientId}")->table('crm_lender_api_logs')->insert([
                'crm_lender_api_id' => null,
                'lead_id'           => $leadId,
                'lender_id'         => 0,
                'user_id'           => null,
                'request_url'       => $url,
                'request_method'    => strtoupper($method),
                'request_headers'   => json_encode(['Authorization' => 'Bearer ***']),
                'request_payload'   => is_string($requestPayload) ? $requestPayload : json_encode($requestPayload),
                'response_code'     => $statusCode ?: null,
                'response_body'     => is_string($responseBody) ? $responseBody : json_encode($responseBody),
                'status'            => $status,
                'error_message'     => $errorMessage,
                'duration_ms'       => $durationMs,
                'attempt'           => $attempt,
                'created_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[OnDeckService] Failed to write API log: ' . $e->getMessage());
        }
    }
}
