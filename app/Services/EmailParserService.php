<?php

namespace App\Services;

use App\Jobs\ProcessEmailParsedAttachmentJob;
use App\Models\Client\EmailParseAuditLog;
use App\Models\Client\EmailParsedApplication;
use App\Models\Client\EmailParsedAttachment;
use App\Models\Client\CrmLeadRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmailParserService
{
    protected GmailOAuthService $oauthService;
    protected GmailMailboxService $mailboxService;
    protected LeadEavService $eavService;

    public function __construct()
    {
        $this->oauthService = new GmailOAuthService();
        $this->mailboxService = new GmailMailboxService();
        $this->eavService = new LeadEavService();
    }

    /**
     * Scan a user's Gmail inbox for PDF attachments and queue them for processing.
     */
    public function scanInbox(int $userId, int $clientId, ?string $query = null, int $maxResults = 20): int
    {
        $conn = "mysql_{$clientId}";

        EmailParseAuditLog::log($conn, $userId, 'scan_triggered', null, null, null, [
            'query'       => $query,
            'max_results' => $maxResults,
        ]);

        $searchQuery = $query ?? 'has:attachment filename:pdf is:unread';
        $result = $this->mailboxService->listEmails($userId, 'INBOX', $maxResults, null, $searchQuery);

        if (!$result || isset($result['error']) || empty($result['emails'])) {
            return 0;
        }

        $newCount = 0;

        foreach ($result['emails'] as $email) {
            $messageId = $email['id'] ?? null;
            if (!$messageId) {
                continue;
            }

            // Fetch full email with attachments
            $fullEmail = $this->mailboxService->getEmail($userId, $messageId);
            if (!$fullEmail || empty($fullEmail['attachments'])) {
                continue;
            }

            foreach ($fullEmail['attachments'] as $att) {
                $attId = $att['id'] ?? $att['attachment_id'] ?? null;
                $filename = $att['filename'] ?? '';
                $mimeType = $att['mime_type'] ?? '';

                // Only process PDFs
                if ($mimeType !== 'application/pdf' && !str_ends_with(strtolower($filename), '.pdf')) {
                    continue;
                }

                // Dedup check
                $exists = EmailParsedAttachment::on($conn)
                    ->where('gmail_message_id', $messageId)
                    ->where('gmail_attachment_id', $attId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $record = EmailParsedAttachment::on($conn)->create([
                    'gmail_message_id'    => $messageId,
                    'gmail_attachment_id' => $attId,
                    'user_id'             => $userId,
                    'thread_id'           => $fullEmail['thread_id'] ?? null,
                    'email_from'          => $fullEmail['from']['email'] ?? ($fullEmail['from']['name'] ?? null),
                    'email_subject'       => $fullEmail['subject'] ?? null,
                    'email_date'          => $fullEmail['date'] ? date('Y-m-d H:i:s', strtotime($fullEmail['date'])) : null,
                    'filename'            => $filename,
                    'mime_type'           => $mimeType ?: 'application/pdf',
                    'file_size'           => $att['size'] ?? 0,
                    'parse_status'        => 'pending',
                    'doc_type'            => 'pending',
                ]);

                EmailParseAuditLog::log(
                    $conn, $userId, 'attachment_downloaded',
                    'attachment', $record->id, $messageId,
                    ['filename' => $filename]
                );

                dispatch(new ProcessEmailParsedAttachmentJob($record->id, $userId, $clientId));

                $newCount++;
            }
        }

        return $newCount;
    }

    /**
     * Download a specific attachment from Gmail API and save to local storage.
     */
    public function downloadAttachment(int $userId, string $messageId, string $attachmentId, int $clientId, string $filename): ?string
    {
        $token = $this->oauthService->getValidAccessToken($userId);
        if (!$token) {
            Log::warning('[EmailParser] No valid access token', ['user_id' => $userId]);
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(60)
                ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}/attachments/{$attachmentId}");

            if (!$response->successful()) {
                Log::error('[EmailParser] Failed to download attachment', [
                    'user_id'    => $userId,
                    'message_id' => $messageId,
                    'status'     => $response->status(),
                ]);
                return null;
            }

            $data = $response->json('data');
            if (!$data) {
                return null;
            }

            // Decode URL-safe base64
            $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
            if ($decoded === false) {
                return null;
            }

            $relativePath = "email-parser/{$clientId}/{$messageId}/{$filename}";
            Storage::disk('local')->put($relativePath, $decoded);

            return $relativePath;

        } catch (\Throwable $e) {
            Log::error('[EmailParser] Download attachment exception', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Classify a downloaded PDF document by type.
     */
    public function classifyDocument(string $localPath, string $filename): array
    {
        // Step 1 — keyword match on filename (fast, free)
        $lowerFilename = strtolower($filename);
        $keywordMap = [
            'application' => ['application', 'app', 'mca', 'funding'],
            'bank_statement' => ['bank', 'statement'],
            'void_cheque' => ['void', 'cheque', 'check'],
            'invoice' => ['invoice'],
        ];

        foreach ($keywordMap as $docType => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lowerFilename, $keyword)) {
                    return [
                        'doc_type'   => $docType,
                        'confidence' => 85.0,
                        'method'     => 'keyword',
                    ];
                }
            }
        }

        // Step 2 — Claude AI vision fallback
        return $this->classifyWithAI($localPath);
    }

    /**
     * Classify document using Claude AI.
     */
    protected function classifyWithAI(string $localPath): array
    {
        $fullPath = Storage::disk('local')->path($localPath);
        if (!file_exists($fullPath)) {
            return ['doc_type' => 'unknown', 'confidence' => 0, 'method' => 'ai_vision'];
        }

        $pdfBase64 = base64_encode(file_get_contents($fullPath));

        try {
            $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 300,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'   => 'document',
                                'source' => [
                                    'type'       => 'base64',
                                    'media_type' => 'application/pdf',
                                    'data'       => $pdfBase64,
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Classify this PDF document into exactly one of these categories: application, bank_statement, void_cheque, invoice, unknown. Respond ONLY with valid JSON: {"doc_type": "...", "confidence": 0-100, "reasoning": "..."}',
                            ],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::warning('[EmailParser] AI classification failed', ['status' => $response->status()]);
                return ['doc_type' => 'unknown', 'confidence' => 0, 'method' => 'ai_vision'];
            }

            $text = $response->json('content.0.text', '');
            // Extract JSON from response
            if (preg_match('/\{[^}]+\}/', $text, $matches)) {
                $parsed = json_decode($matches[0], true);
                if ($parsed && isset($parsed['doc_type'])) {
                    $allowed = ['application', 'bank_statement', 'void_cheque', 'invoice', 'unknown'];
                    $docType = in_array($parsed['doc_type'], $allowed) ? $parsed['doc_type'] : 'unknown';
                    return [
                        'doc_type'   => $docType,
                        'confidence' => (float) ($parsed['confidence'] ?? 50),
                        'method'     => 'ai_vision',
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('[EmailParser] AI classification exception', ['error' => $e->getMessage()]);
        }

        return ['doc_type' => 'unknown', 'confidence' => 0, 'method' => 'ai_vision'];
    }

    /**
     * Parse an MCA application PDF and extract structured data.
     */
    public function parseApplication(string $localPath): ?array
    {
        $fullPath = Storage::disk('local')->path($localPath);
        if (!file_exists($fullPath)) {
            return null;
        }

        $pdfBase64 = base64_encode(file_get_contents($fullPath));

        $prompt = <<<'PROMPT'
Extract all structured data from this merchant cash advance (MCA) application PDF.
Return ONLY valid JSON with this exact structure. For each field, provide {"value": "...", "confidence": 0-100}.
If a field is not found, set value to null and confidence to 0.

{
  "business": {
    "legal_name": {"value": null, "confidence": 0},
    "dba_name": {"value": null, "confidence": 0},
    "ein": {"value": null, "confidence": 0},
    "phone": {"value": null, "confidence": 0},
    "address": {"value": null, "confidence": 0},
    "city": {"value": null, "confidence": 0},
    "state": {"value": null, "confidence": 0},
    "zip": {"value": null, "confidence": 0},
    "type": {"value": null, "confidence": 0}
  },
  "owner": {
    "first_name": {"value": null, "confidence": 0},
    "last_name": {"value": null, "confidence": 0},
    "email": {"value": null, "confidence": 0},
    "phone": {"value": null, "confidence": 0},
    "ssn_last4": {"value": null, "confidence": 0},
    "dob": {"value": null, "confidence": 0},
    "ownership_pct": {"value": null, "confidence": 0}
  },
  "financial": {
    "annual_revenue": {"value": null, "confidence": 0},
    "monthly_revenue": {"value": null, "confidence": 0},
    "requested_amount": {"value": null, "confidence": 0},
    "use_of_funds": {"value": null, "confidence": 0},
    "time_in_business": {"value": null, "confidence": 0}
  }
}
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 2000,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'   => 'document',
                                'source' => [
                                    'type'       => 'base64',
                                    'media_type' => 'application/pdf',
                                    'data'       => $pdfBase64,
                                ],
                            ],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('[EmailParser] Application parse failed', ['status' => $response->status()]);
                return null;
            }

            $text = $response->json('content.0.text', '');

            // Extract JSON from response (may include markdown fences)
            if (preg_match('/\{[\s\S]+\}/m', $text, $matches)) {
                $raw = json_decode($matches[0], true);
                if (!$raw) {
                    return null;
                }

                return $this->flattenApplicationExtraction($raw);
            }
        } catch (\Throwable $e) {
            Log::error('[EmailParser] Application parse exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Flatten the nested AI extraction into email_parsed_applications columns.
     */
    protected function flattenApplicationExtraction(array $raw): array
    {
        $confidences = [];
        $getValue = function ($section, $field) use ($raw, &$confidences) {
            $item = $raw[$section][$field] ?? null;
            if (!$item || !isset($item['value'])) {
                return null;
            }
            if ($item['confidence'] ?? 0) {
                $confidences[] = (float) $item['confidence'];
            }
            return $item['value'];
        };

        $result = [
            'business_name'    => $getValue('business', 'legal_name'),
            'business_dba'     => $getValue('business', 'dba_name'),
            'business_ein'     => $getValue('business', 'ein'),
            'business_address' => $getValue('business', 'address'),
            'business_city'    => $getValue('business', 'city'),
            'business_state'   => $getValue('business', 'state'),
            'business_zip'     => $getValue('business', 'zip'),
            'business_type'    => $getValue('business', 'type'),
            'owner_first_name' => $getValue('owner', 'first_name'),
            'owner_last_name'  => $getValue('owner', 'last_name'),
            'owner_email'      => $getValue('owner', 'email'),
            'owner_phone'      => $getValue('owner', 'phone'),
            'owner_ssn_last4'  => $getValue('owner', 'ssn_last4'),
            'annual_revenue'   => $getValue('financial', 'annual_revenue'),
            'monthly_revenue'  => $getValue('financial', 'monthly_revenue'),
            'requested_amount' => $getValue('financial', 'requested_amount'),
            'use_of_funds'     => $getValue('financial', 'use_of_funds'),
            'time_in_business' => $getValue('financial', 'time_in_business'),
            'raw_extraction'   => $raw,
            'confidence_score' => $confidences ? round(array_sum($confidences) / count($confidences), 2) : null,
        ];

        // Clean numeric values
        foreach (['annual_revenue', 'monthly_revenue', 'requested_amount'] as $numField) {
            if ($result[$numField] !== null) {
                $result[$numField] = (float) preg_replace('/[^0-9.]/', '', (string) $result[$numField]);
            }
        }

        return $result;
    }

    /**
     * Parse a bank statement PDF and extract key data.
     */
    public function parseBankStatement(string $localPath): ?array
    {
        $fullPath = Storage::disk('local')->path($localPath);
        if (!file_exists($fullPath)) {
            return null;
        }

        $pdfBase64 = base64_encode(file_get_contents($fullPath));

        $prompt = 'Extract the following from this bank statement PDF. Return ONLY valid JSON: '
            . '{"bank_name": null, "account_holder": null, "account_last4": null, '
            . '"statement_period": null, "beginning_balance": null, "ending_balance": null, '
            . '"total_deposits": null, "total_withdrawals": null}';

        try {
            $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 1000,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'   => 'document',
                                'source' => [
                                    'type'       => 'base64',
                                    'media_type' => 'application/pdf',
                                    'data'       => $pdfBase64,
                                ],
                            ],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                return null;
            }

            $text = $response->json('content.0.text', '');
            if (preg_match('/\{[\s\S]+\}/m', $text, $matches)) {
                return json_decode($matches[0], true);
            }
        } catch (\Throwable $e) {
            Log::error('[EmailParser] Bank statement parse exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Parse a void cheque PDF and extract routing/account info.
     */
    public function parseVoidCheque(string $localPath): ?array
    {
        $fullPath = Storage::disk('local')->path($localPath);
        if (!file_exists($fullPath)) {
            return null;
        }

        $pdfBase64 = base64_encode(file_get_contents($fullPath));

        $prompt = 'Extract the following from this void cheque PDF. Return ONLY valid JSON: '
            . '{"routing_number": null, "account_number": null, "bank_name": null, "account_holder": null}';

        try {
            $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 500,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'   => 'document',
                                'source' => [
                                    'type'       => 'base64',
                                    'media_type' => 'application/pdf',
                                    'data'       => $pdfBase64,
                                ],
                            ],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                return null;
            }

            $text = $response->json('content.0.text', '');
            if (preg_match('/\{[\s\S]+\}/m', $text, $matches)) {
                return json_decode($matches[0], true);
            }
        } catch (\Throwable $e) {
            Log::error('[EmailParser] Void cheque parse exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Create a CRM lead from a parsed application.
     */
    public function createLeadFromApplication(int $applicationId, int $clientId, int $userId, array $overrides = []): int
    {
        $conn = "mysql_{$clientId}";

        $app = EmailParsedApplication::on($conn)->findOrFail($applicationId);

        // System columns for crm_leads
        $systemCols = [
            'lead_status' => $overrides['lead_status'] ?? 'new_lead',
            'created_by'  => $userId,
            'lead_type'   => $overrides['lead_type'] ?? 'merchant',
        ];

        $lead = CrmLeadRecord::on($conn)->create($systemCols);
        $leadId = $lead->id;

        // Map application fields → EAV field keys
        $eavFields = [
            'company_name'  => $overrides['company_name'] ?? $app->business_name,
            'first_name'    => $overrides['first_name'] ?? $app->owner_first_name,
            'last_name'     => $overrides['last_name'] ?? $app->owner_last_name,
            'email'         => $overrides['email'] ?? $app->owner_email,
            'phone_number'  => $overrides['phone_number'] ?? $app->owner_phone,
            'address'       => $overrides['address'] ?? $app->business_address,
            'city'          => $overrides['city'] ?? $app->business_city,
            'state'         => $overrides['state'] ?? $app->business_state,
            'zip'           => $overrides['zip'] ?? $app->business_zip,
            'loan_amount'   => $overrides['loan_amount'] ?? $app->requested_amount,
        ];

        // Apply any additional overrides
        foreach ($overrides as $key => $val) {
            if (!in_array($key, ['lead_status', 'lead_type']) && $val !== null) {
                $eavFields[$key] = $val;
            }
        }

        // Filter nulls
        $eavFields = array_filter($eavFields, fn ($v) => $v !== null);

        $this->eavService->save((string) $clientId, $leadId, $eavFields);

        // Update application status
        $app->status  = 'lead_created';
        $app->lead_id = $leadId;
        $app->save();

        // Auto-link all attachments from same email
        EmailParsedAttachment::on($conn)
            ->where('gmail_message_id', $app->gmail_message_id)
            ->update(['linked_lead_id' => $leadId]);

        EmailParseAuditLog::log(
            $conn, $userId, 'lead_created',
            'application', $applicationId, $app->gmail_message_id,
            ['lead_id' => $leadId]
        );

        return $leadId;
    }

    /**
     * Auto-process a parsed application: find existing lead or create new one.
     * Called automatically after successful application parsing.
     *
     * Match order:
     *  1. Email match (owner_email → crm_lead_values.email)
     *  2. Business name match (business_name → crm_lead_values.company_name)
     *  3. Phone match (owner_phone → crm_lead_values.phone_number)
     *
     * If matched: update lead's EAV fields + link attachment → 'accepted'
     * If no match: create new lead → 'lead_created'
     */
    public function autoProcessApplication(int $applicationId, int $clientId, int $userId): array
    {
        $conn = "mysql_{$clientId}";

        $app = EmailParsedApplication::on($conn)->find($applicationId);
        if (!$app || $app->lead_id) {
            return ['action' => 'skipped', 'reason' => 'already processed'];
        }

        // Try to find an existing lead by email, company name, or phone
        $existingLeadId = $this->findExistingLead($clientId, $app);

        if ($existingLeadId) {
            // Update existing lead with new data from the application
            $eavFields = array_filter([
                'company_name'  => $app->business_name,
                'first_name'    => $app->owner_first_name,
                'last_name'     => $app->owner_last_name,
                'email'         => $app->owner_email,
                'phone_number'  => $app->owner_phone,
                'address'       => $app->business_address,
                'city'          => $app->business_city,
                'state'         => $app->business_state,
                'zip'           => $app->business_zip,
                'loan_amount'   => $app->requested_amount,
            ], fn ($v) => $v !== null && $v !== '');

            $this->eavService->save((string) $clientId, $existingLeadId, $eavFields);

            $app->status  = 'accepted';
            $app->lead_id = $existingLeadId;
            $app->save();

            // Link all attachments from same email to this lead
            EmailParsedAttachment::on($conn)
                ->where('gmail_message_id', $app->gmail_message_id)
                ->update(['linked_lead_id' => $existingLeadId]);

            EmailParseAuditLog::log(
                $conn, $userId, 'lead_updated',
                'application', $applicationId, $app->gmail_message_id,
                ['lead_id' => $existingLeadId, 'match' => 'existing']
            );

            return ['action' => 'updated', 'lead_id' => $existingLeadId];
        }

        // No match — create new lead
        $leadId = $this->createLeadFromApplication($applicationId, $clientId, $userId);

        return ['action' => 'created', 'lead_id' => $leadId];
    }

    /**
     * Find an existing lead by matching email, company name, or phone from a parsed application.
     */
    protected function findExistingLead(int $clientId, EmailParsedApplication $app): ?int
    {
        $conn = "mysql_{$clientId}";

        // 1. Match by email
        if ($app->owner_email) {
            $match = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('field_key', 'email')
                ->whereRaw('LOWER(field_value) = ?', [strtolower($app->owner_email)])
                ->value('lead_id');

            if ($match) {
                // Verify lead still exists
                $exists = CrmLeadRecord::on($conn)->where('id', $match)->whereNull('deleted_at')->exists();
                if ($exists) return (int) $match;
            }
        }

        // 2. Match by business name
        if ($app->business_name) {
            $match = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('field_key', 'company_name')
                ->whereRaw('LOWER(field_value) = ?', [strtolower($app->business_name)])
                ->value('lead_id');

            if ($match) {
                $exists = CrmLeadRecord::on($conn)->where('id', $match)->whereNull('deleted_at')->exists();
                if ($exists) return (int) $match;
            }
        }

        // 3. Match by phone
        if ($app->owner_phone) {
            $normalized = preg_replace('/[^0-9]/', '', $app->owner_phone);
            if (strlen($normalized) >= 7) {
                $match = DB::connection($conn)
                    ->table('crm_lead_values')
                    ->where('field_key', 'phone_number')
                    ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(field_value, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?", ['%' . substr($normalized, -10)])
                    ->value('lead_id');

                if ($match) {
                    $exists = CrmLeadRecord::on($conn)->where('id', $match)->whereNull('deleted_at')->exists();
                    if ($exists) return (int) $match;
                }
            }
        }

        return null;
    }

    /**
     * Auto-link a non-application attachment (bank statement, void cheque) to an existing lead.
     * Matches by sender email → lead email, or by same gmail thread/sender as a linked attachment.
     */
    public function autoLinkDocument(int $attachmentId, int $clientId, int $userId): ?int
    {
        $conn = "mysql_{$clientId}";

        $attachment = EmailParsedAttachment::on($conn)->find($attachmentId);
        if (!$attachment || $attachment->linked_lead_id) {
            return $attachment->linked_lead_id ?? null;
        }

        // 1. Check if another attachment from the same email is already linked
        $linkedSibling = EmailParsedAttachment::on($conn)
            ->where('gmail_message_id', $attachment->gmail_message_id)
            ->whereNotNull('linked_lead_id')
            ->value('linked_lead_id');

        if ($linkedSibling) {
            $attachment->linked_lead_id = $linkedSibling;
            $attachment->save();

            EmailParseAuditLog::log(
                $conn, $userId, 'document_linked',
                'attachment', $attachmentId, $attachment->gmail_message_id,
                ['lead_id' => $linkedSibling, 'match' => 'sibling_attachment']
            );

            return (int) $linkedSibling;
        }

        // 2. Match sender email to a lead's email
        if ($attachment->email_from) {
            $senderEmail = $attachment->email_from;
            // Extract just the email part if it contains a name
            if (preg_match('/<([^>]+)>/', $senderEmail, $m)) {
                $senderEmail = $m[1];
            }

            $leadId = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('field_key', 'email')
                ->whereRaw('LOWER(field_value) = ?', [strtolower($senderEmail)])
                ->value('lead_id');

            if ($leadId) {
                $exists = CrmLeadRecord::on($conn)->where('id', $leadId)->whereNull('deleted_at')->exists();
                if ($exists) {
                    $attachment->linked_lead_id = $leadId;
                    $attachment->save();

                    EmailParseAuditLog::log(
                        $conn, $userId, 'document_linked',
                        'attachment', $attachmentId, $attachment->gmail_message_id,
                        ['lead_id' => $leadId, 'match' => 'sender_email']
                    );

                    return (int) $leadId;
                }
            }
        }

        // 3. Check if same sender sent an application that was linked
        if ($attachment->email_from) {
            $linkedFromSameSender = EmailParsedAttachment::on($conn)
                ->where('email_from', $attachment->email_from)
                ->whereNotNull('linked_lead_id')
                ->value('linked_lead_id');

            if ($linkedFromSameSender) {
                $attachment->linked_lead_id = $linkedFromSameSender;
                $attachment->save();

                EmailParseAuditLog::log(
                    $conn, $userId, 'document_linked',
                    'attachment', $attachmentId, $attachment->gmail_message_id,
                    ['lead_id' => $linkedFromSameSender, 'match' => 'same_sender']
                );

                return (int) $linkedFromSameSender;
            }
        }

        return null;
    }
}
