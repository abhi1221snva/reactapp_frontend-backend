<?php

namespace App\Services;

use App\Model\Client\CrmLeadActivity;
use App\Model\Client\CrmOffer;
use App\Model\Client\Lender;
use App\Models\Client\CrmLeadNote;
use App\Models\Client\CrmLeadRecord;
use App\Models\Client\EmailLenderConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LenderEmailIntelligenceService
{
    protected GmailMailboxService $mailboxService;
    protected LeadEavService $eavService;
    protected LeadLenderService $lenderService;

    public function __construct()
    {
        $this->mailboxService = new GmailMailboxService();
        $this->eavService = new LeadEavService();
        $this->lenderService = new LeadLenderService();
    }

    /**
     * Scan Gmail for emails matching known lender addresses and link to CRM leads.
     */
    public function scanLenderEmails(int $userId, int $clientId, ?string $query = null, int $maxResults = 50): array
    {
        $conn = "mysql_{$clientId}";

        // 1. Build lender email lookup
        $lenders = Lender::on($conn)->where('status', 1)->get();
        $emailToLender = [];
        $emailFields = ['email', 'secondary_email', 'secondary_email2', 'secondary_email3', 'secondary_email4'];
        foreach ($lenders as $lender) {
            foreach ($emailFields as $field) {
                if (!empty($lender->$field)) {
                    $emailToLender[strtolower(trim($lender->$field))] = $lender;
                }
            }
        }

        if (empty($emailToLender)) {
            return [
                'total_emails_scanned' => 0, 'lender_matches' => 0, 'conversations_logged' => 0,
                'offers_detected' => 0, 'ai_analyzed' => 0, 'submissions_updated' => 0, 'unmatched_logged' => 0,
            ];
        }

        // 2. Build merchant name lookup from EAV
        $leadIds = CrmLeadRecord::on($conn)->pluck('id')->toArray();
        $merchantNames = []; // [lead_id => [name1, name2, ...]]

        if (!empty($leadIds)) {
            $eavData = $this->eavService->load((string) $clientId, $leadIds);
            foreach ($eavData as $leadId => $fields) {
                $names = [];
                foreach (['company_name', 'business_dba', 'business_name'] as $key) {
                    if (!empty($fields[$key])) {
                        $normalized = strtolower(trim($fields[$key]));
                        if (strlen($normalized) >= 3) {
                            $names[] = $normalized;
                        }
                    }
                }
                if ($names) {
                    $merchantNames[$leadId] = array_unique($names);
                }
            }
        }

        // 3. Fetch emails from Gmail (INBOX + SENT)
        $defaultQuery = $query ?? 'newer_than:30d';
        $allEmails = [];

        $inboxResult = $this->mailboxService->listEmails($userId, 'INBOX', $maxResults, null, $defaultQuery);
        if ($inboxResult && !isset($inboxResult['error'])) {
            foreach ($inboxResult['emails'] ?? [] as $email) {
                $email['_scan_source'] = 'inbox';
                $allEmails[$email['id']] = $email;
            }
        }

        $sentResult = $this->mailboxService->listEmails($userId, 'SENT', $maxResults, null, $defaultQuery);
        if ($sentResult && !isset($sentResult['error'])) {
            foreach ($sentResult['emails'] ?? [] as $email) {
                if (!isset($allEmails[$email['id']])) {
                    $email['_scan_source'] = 'sent';
                    $allEmails[$email['id']] = $email;
                }
            }
        }

        // 4. Process each email
        $totalScanned = count($allEmails);
        $lenderMatches = 0;
        $conversationsLogged = 0;
        $offersDetected = 0;
        $aiAnalyzed = 0;
        $submissionsUpdated = 0;
        $unmatchedLogged = 0;

        foreach ($allEmails as $email) {
            $result = $this->processEmail($email, $emailToLender, $merchantNames, $userId, $clientId, $conn);
            if ($result) {
                $lenderMatches++;
                if ($result['logged']) {
                    $conversationsLogged++;
                }
                if ($result['offer_detected']) {
                    $offersDetected++;
                }
                if (!empty($result['ai_analyzed'])) {
                    $aiAnalyzed++;
                }
                if (!empty($result['submission_updated'])) {
                    $submissionsUpdated++;
                }
                if (!empty($result['unmatched_logged'])) {
                    $unmatchedLogged++;
                }
            }
        }

        return [
            'total_emails_scanned' => $totalScanned,
            'lender_matches'       => $lenderMatches,
            'conversations_logged' => $conversationsLogged,
            'offers_detected'      => $offersDetected,
            'ai_analyzed'          => $aiAnalyzed,
            'submissions_updated'  => $submissionsUpdated,
            'unmatched_logged'     => $unmatchedLogged,
        ];
    }

    /**
     * Process a single email: match lender, match merchant (verbatim + AI fuzzy), log conversation.
     */
    protected function processEmail(array $email, array $emailToLender, array $merchantNames, int $userId, int $clientId, string $conn): ?array
    {
        $messageId = $email['id'] ?? null;
        if (!$messageId) {
            return null;
        }

        // Extract email addresses from from/to fields
        $fromField = $email['from'] ?? '';
        $toField   = $email['to'] ?? '';
        $fromRaw = is_array($fromField) ? ($fromField['email'] ?? '') : (string) $fromField;
        $toRaw   = is_array($toField) ? ($toField['email'] ?? '') : (string) $toField;
        $fromDisplay = is_array($fromField) ? trim(($fromField['name'] ?? '') . ' <' . ($fromField['email'] ?? '') . '>') : (string) $fromField;
        $toDisplay   = is_array($toField) ? trim(($toField['name'] ?? '') . ' <' . ($toField['email'] ?? '') . '>') : (string) $toField;
        $fromEmail = $this->extractEmailAddress($fromRaw);
        $toEmail   = $this->extractEmailAddress($toRaw);

        // Check if from or to matches a known lender
        $lender = null;
        $direction = null;

        if ($fromEmail && isset($emailToLender[strtolower($fromEmail)])) {
            $lender = $emailToLender[strtolower($fromEmail)];
            $direction = 'inbound';
        } elseif ($toEmail && isset($emailToLender[strtolower($toEmail)])) {
            $lender = $emailToLender[strtolower($toEmail)];
            $direction = 'outbound';
        }

        if (!$lender) {
            return null;
        }

        // Dedup check (moved before body fetch for efficiency)
        $exists = EmailLenderConversation::on($conn)
            ->where('gmail_message_id', $messageId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return ['logged' => false, 'offer_detected' => false];
        }

        // Try to get full email body for merchant matching
        $subject = $email['subject'] ?? '';
        $snippet = $email['snippet'] ?? '';
        $bodyText = '';
        $attachments = [];

        $fullEmail = $this->mailboxService->getEmail($userId, $messageId);
        if ($fullEmail) {
            $bodyText = strip_tags($fullEmail['body_text'] ?? $fullEmail['body_html'] ?? '');
            $attachments = $fullEmail['attachments'] ?? [];
        }

        // Search for merchant name in subject + body (verbatim match)
        $matchedLeadId = null;
        $matchedMerchantName = null;
        $detectionSource = null;

        $subjectLower = strtolower($subject);
        $bodyLower = strtolower($bodyText ?: $snippet);

        foreach ($merchantNames as $leadId => $names) {
            foreach ($names as $name) {
                $inSubject = str_contains($subjectLower, $name);
                $inBody = str_contains($bodyLower, $name);

                if ($inSubject || $inBody) {
                    $matchedLeadId = $leadId;
                    $matchedMerchantName = $name;
                    $detectionSource = ($inSubject && $inBody) ? 'both' : ($inSubject ? 'subject' : 'body');
                    break 2;
                }
            }
        }

        // AI analysis for inbound lender emails
        $aiResult = null;
        $aiAnalyzed = false;
        $submissionUpdated = false;
        $unmatchedLogged = false;

        if ($direction === 'inbound') {
            // Call AI once per inbound email for both merchant matching and response detection
            $aiResult = $this->analyzeWithAI($subject, $bodyText ?: $snippet, $lender->lender_name);
            $aiAnalyzed = ($aiResult !== null);

            // If no verbatim match, try AI fuzzy matching
            if (!$matchedLeadId && $aiResult && !empty($aiResult['merchant_name'])) {
                $fuzzyMatch = $this->fuzzyMatchMerchant($aiResult['merchant_name'], $merchantNames);
                if ($fuzzyMatch) {
                    $matchedLeadId = $fuzzyMatch['lead_id'];
                    $matchedMerchantName = $fuzzyMatch['matched_name'];
                    $detectionSource = 'ai_fuzzy';
                }
            }

            // If still no match, log as unmatched and return
            if (!$matchedLeadId) {
                $this->logUnmatchedConversation(
                    $email, $lender, $direction, $fromDisplay, $toDisplay,
                    $subject, $bodyText ?: $snippet, $attachments, $aiResult,
                    $userId, $conn
                );
                return ['logged' => false, 'offer_detected' => false, 'ai_analyzed' => $aiAnalyzed, 'unmatched_logged' => true];
            }
        }

        // Outbound emails without verbatim match are still dropped
        if (!$matchedLeadId) {
            return null;
        }

        // Full body text for storage
        $bodyFull = $bodyText ?: $snippet;
        $bodyPreview = $bodyFull;

        // Parse email date
        $emailDate = null;
        if (!empty($email['date'])) {
            try {
                $emailDate = Carbon::parse($email['date']);
            } catch (\Throwable $e) {
                $emailDate = null;
            }
        }

        // Attachment info
        $hasAttachments = !empty($attachments);
        $attachmentCount = count($attachments);
        $attachmentFilenames = $hasAttachments
            ? array_map(fn($a) => $a['filename'] ?? 'unknown', $attachments)
            : null;

        // Auto-update lender submission if AI detected a response status
        $submissionId = null;
        if ($direction === 'inbound' && $aiResult && ($aiResult['confidence'] ?? 0) >= 70) {
            $subResult = $this->autoUpdateSubmission(
                $matchedLeadId, $lender->id, $aiResult, (string) $clientId, $userId
            );
            if ($subResult) {
                $submissionUpdated = true;
                $submissionId = $subResult;
            }
        }

        // Create AI-based offer if approval with amount detected
        $offerDetected = false;
        $offerDetails = null;

        if ($direction === 'inbound' && $aiResult
            && ($aiResult['response_status'] ?? '') === 'approved'
            && !empty($aiResult['offer_amount'])
            && ($aiResult['confidence'] ?? 0) >= 70
        ) {
            $aiOfferResult = $this->createAiOffer(
                $matchedLeadId, $lender->id, $lender->lender_name, $aiResult, $conn, $userId
            );
            if ($aiOfferResult) {
                $offerDetected = true;
                $offerDetails = $aiOfferResult;
            }
        }

        // Keyword-based offer detection (fallback, still runs)
        if (!$offerDetected) {
            $offerResult = $this->detectOffer($subject, $bodyPreview, $matchedLeadId, $lender->id, $lender->lender_name, $conn, $userId);
            if ($offerResult) {
                $offerDetected = true;
                $offerDetails = $offerResult;
            }
        }

        // Create activity entry
        $activityId = null;
        try {
            $dirLabel = $direction === 'inbound' ? 'from' : 'to';
            $activity = CrmLeadActivity::on($conn)->create([
                'lead_id'       => $matchedLeadId,
                'user_id'       => $userId,
                'activity_type' => 'lender_email',
                'subject'       => "Email {$dirLabel} {$lender->lender_name}: " . mb_substr($subject, 0, 200),
                'body'          => mb_substr($bodyPreview, 0, 500),
                'meta'          => [
                    'lender_id'          => $lender->id,
                    'gmail_message_id'   => $messageId,
                    'direction'          => $direction,
                    'offer_detected'     => $offerDetected,
                    'ai_response_status' => $aiResult['response_status'] ?? null,
                    'ai_confidence'      => $aiResult['confidence'] ?? null,
                ],
                'source_type' => 'api',
            ]);
            $activityId = $activity->id;
        } catch (\Throwable $e) {
            Log::warning('LenderEmailIntel: Failed to create activity', ['error' => $e->getMessage()]);
        }

        // Create note
        $noteId = null;
        try {
            $note = CrmLeadNote::on($conn)->create([
                'lead_id'    => $matchedLeadId,
                'note'       => "[Lender: {$lender->lender_name}] {$subject}\n\n{$bodyFull}",
                'note_type'  => 'lender_email',
                'created_by' => $userId,
                'user_type'  => 'system',
            ]);
            $noteId = $note->id;
        } catch (\Throwable $e) {
            Log::warning('LenderEmailIntel: Failed to create note', ['error' => $e->getMessage()]);
        }

        // Create conversation record
        try {
            EmailLenderConversation::on($conn)->create([
                'user_id'                => $userId,
                'lead_id'                => $matchedLeadId,
                'lender_id'              => $lender->id,
                'gmail_message_id'       => $messageId,
                'gmail_thread_id'        => $email['thread_id'] ?? null,
                'direction'              => $direction,
                'from_email'             => $fromDisplay,
                'to_email'               => $toDisplay,
                'subject'                => mb_substr($subject, 0, 1000),
                'body_preview'           => $bodyPreview,
                'has_attachments'        => $hasAttachments,
                'attachment_count'       => $attachmentCount,
                'attachment_filenames'   => $attachmentFilenames,
                'detected_merchant_name' => $matchedMerchantName,
                'detection_source'       => $detectionSource,
                'offer_detected'         => $offerDetected,
                'offer_details'          => $offerDetails,
                'conversation_date'      => $emailDate,
                'activity_id'            => $activityId,
                'note_id'                => $noteId,
                'ai_response_status'     => $aiResult['response_status'] ?? null,
                'ai_merchant_name'       => $aiResult['merchant_name'] ?? null,
                'ai_confidence'          => $aiResult['confidence'] ?? null,
                'ai_raw_response'        => $aiResult,
                'submission_id'          => $submissionId,
            ]);
        } catch (\Throwable $e) {
            Log::error('LenderEmailIntel: Failed to create conversation', [
                'error'      => $e->getMessage(),
                'message_id' => $messageId,
            ]);
            return ['logged' => false, 'offer_detected' => $offerDetected, 'ai_analyzed' => $aiAnalyzed, 'submission_updated' => $submissionUpdated];
        }

        return [
            'logged'              => true,
            'offer_detected'      => $offerDetected,
            'ai_analyzed'         => $aiAnalyzed,
            'submission_updated'  => $submissionUpdated,
            'unmatched_logged'    => $unmatchedLogged,
        ];
    }

    /**
     * Use Claude Haiku to extract merchant name and classify lender response status.
     */
    protected function analyzeWithAI(string $subject, string $body, string $lenderName): ?array
    {
        $apiKey = config('services.anthropic.api_key');
        $model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');

        if (empty($apiKey)) {
            Log::warning('LenderEmailIntel: Anthropic API key not configured');
            return null;
        }

        // Truncate body to ~3000 chars to keep cost low
        $truncatedBody = mb_substr($body, 0, 3000);

        $prompt = <<<PROMPT
You are analyzing a lender email in a business financing / merchant cash advance context.

Lender: {$lenderName}
Subject: {$subject}

Email body:
{$truncatedBody}

Extract the following as JSON (no markdown, no code fences — raw JSON only):

{
  "merchant_name": "the business/merchant DBA or legal name this email is about, or null if not identifiable",
  "response_status": "one of: approved, declined, needs_documents, under_review, received, unknown",
  "confidence": 0-100,
  "offer_amount": dollar amount if this is an approval with a specific amount, otherwise null,
  "reasoning": "1-sentence explanation"
}

Rules:
- "approved" = definitive approval with or without conditions already met
- "declined" = definitive decline/denial
- "needs_documents" = lender requesting additional documents, stips, or information before they can proceed
- "under_review" = application received and being reviewed, no decision yet. Also use for conditional language like "we could approve if..."
- "received" = simple acknowledgment that application/documents were received
- "unknown" = cannot determine the response type
- Distinguish between actual approvals vs conditional language like "we could approve if..." — the latter should be needs_documents or under_review, NOT approved
- confidence should reflect how certain you are about both the merchant name and the response status
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 300,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if (!$response->successful()) {
                Log::warning('LenderEmailIntel: AI API call failed', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';

            // Parse JSON from response (handle possible markdown fences)
            $text = trim($text);
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
            }

            $parsed = json_decode($text, true);
            if (!is_array($parsed)) {
                Log::warning('LenderEmailIntel: AI returned unparseable response', ['raw' => mb_substr($text, 0, 500)]);
                return null;
            }

            // Normalize and validate
            $validStatuses = ['approved', 'declined', 'needs_documents', 'under_review', 'received', 'unknown'];
            $status = $parsed['response_status'] ?? 'unknown';
            if (!in_array($status, $validStatuses)) {
                $status = 'unknown';
            }

            return [
                'merchant_name'   => !empty($parsed['merchant_name']) ? (string) $parsed['merchant_name'] : null,
                'response_status' => $status,
                'confidence'      => max(0, min(100, (int) ($parsed['confidence'] ?? 0))),
                'offer_amount'    => is_numeric($parsed['offer_amount'] ?? null) ? (float) $parsed['offer_amount'] : null,
                'reasoning'       => (string) ($parsed['reasoning'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('LenderEmailIntel: AI analysis failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fuzzy match AI-extracted merchant name against known merchant names from EAV.
     *
     * @return array{lead_id: int, matched_name: string, similarity: float}|null
     */
    protected function fuzzyMatchMerchant(string $aiMerchantName, array $merchantNames): ?array
    {
        $aiNameLower = strtolower(trim($aiMerchantName));
        if (strlen($aiNameLower) < 3) {
            return null;
        }

        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($merchantNames as $leadId => $names) {
            foreach ($names as $name) {
                // Exact substring match (either direction) → 95% similarity
                if (str_contains($aiNameLower, $name) || str_contains($name, $aiNameLower)) {
                    return [
                        'lead_id'      => $leadId,
                        'matched_name' => $name,
                        'similarity'   => 95.0,
                    ];
                }

                // Fuzzy match using similar_text
                similar_text($aiNameLower, $name, $percent);
                if ($percent >= 70 && $percent > $bestSimilarity) {
                    $bestSimilarity = $percent;
                    $bestMatch = [
                        'lead_id'      => $leadId,
                        'matched_name' => $name,
                        'similarity'   => round($percent, 1),
                    ];
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Auto-update the most recent crm_lender_submissions record based on AI-detected response.
     *
     * @return int|null The submission ID that was updated, or null
     */
    protected function autoUpdateSubmission(int $leadId, int $lenderId, array $aiResult, string $clientId, int $userId): ?int
    {
        $status = $aiResult['response_status'] ?? 'unknown';
        if ($status === 'unknown') {
            return null;
        }

        // Map AI status → submission fields
        $statusMap = [
            'approved'        => ['response_status' => 'approved',        'submission_status' => 'approved'],
            'declined'        => ['response_status' => 'declined',        'submission_status' => 'declined'],
            'needs_documents' => ['response_status' => 'needs_documents', 'submission_status' => 'viewed'],
            'under_review'    => ['response_status' => 'under_review',    'submission_status' => 'viewed'],
            'received'        => ['response_status' => 'pending',         'submission_status' => 'viewed'],
        ];

        if (!isset($statusMap[$status])) {
            return null;
        }

        $conn = "mysql_{$clientId}";
        $mapping = $statusMap[$status];

        // Find most recent submission for this lead+lender
        $submission = DB::connection($conn)
            ->table('crm_lender_submissions')
            ->where('lead_id', $leadId)
            ->where('lender_id', $lenderId)
            ->orderByDesc('id')
            ->first();

        if (!$submission) {
            return null;
        }

        // Safety: only update if submission is in an updatable state
        $updatableStatuses = ['submitted', 'viewed', 'pending'];
        if (!in_array($submission->submission_status, $updatableStatuses)) {
            return null;
        }

        // Safety: don't downgrade final statuses
        $finalStatuses = ['approved', 'declined'];
        if (in_array($submission->response_status, $finalStatuses)) {
            return null;
        }

        $responseNote = '[Auto-detected from lender email] ' . ($aiResult['reasoning'] ?? '');

        try {
            $this->lenderService->updateSubmissionResponse(
                $clientId,
                $leadId,
                $submission->id,
                $mapping['response_status'],
                $responseNote,
                $mapping['submission_status'],
                $userId
            );

            return (int) $submission->id;
        } catch (\Throwable $e) {
            Log::warning('LenderEmailIntel: Failed to auto-update submission', [
                'error'         => $e->getMessage(),
                'submission_id' => $submission->id,
            ]);
            return null;
        }
    }

    /**
     * Log an unmatched lender conversation (lead_id=0) for manual review.
     */
    protected function logUnmatchedConversation(
        array $email,
        $lender,
        string $direction,
        string $fromDisplay,
        string $toDisplay,
        string $subject,
        string $body,
        array $attachments,
        ?array $aiResult,
        int $userId,
        string $conn
    ): void {
        $emailDate = null;
        if (!empty($email['date'])) {
            try {
                $emailDate = Carbon::parse($email['date']);
            } catch (\Throwable $e) {
                $emailDate = null;
            }
        }

        $hasAttachments = !empty($attachments);
        $attachmentCount = count($attachments);
        $attachmentFilenames = $hasAttachments
            ? array_map(fn($a) => $a['filename'] ?? 'unknown', $attachments)
            : null;

        try {
            EmailLenderConversation::on($conn)->create([
                'user_id'                => $userId,
                'lead_id'                => 0,
                'lender_id'              => $lender->id,
                'gmail_message_id'       => $email['id'],
                'gmail_thread_id'        => $email['thread_id'] ?? null,
                'direction'              => $direction,
                'from_email'             => $fromDisplay,
                'to_email'               => $toDisplay,
                'subject'                => mb_substr($subject, 0, 1000),
                'body_preview'           => $body,
                'has_attachments'        => $hasAttachments,
                'attachment_count'       => $attachmentCount,
                'attachment_filenames'   => $attachmentFilenames,
                'detected_merchant_name' => $aiResult['merchant_name'] ?? null,
                'detection_source'       => null,
                'offer_detected'         => false,
                'offer_details'          => null,
                'conversation_date'      => $emailDate,
                'activity_id'            => null,
                'note_id'                => null,
                'ai_response_status'     => $aiResult['response_status'] ?? null,
                'ai_merchant_name'       => $aiResult['merchant_name'] ?? null,
                'ai_confidence'          => $aiResult['confidence'] ?? null,
                'ai_raw_response'        => $aiResult,
                'submission_id'          => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LenderEmailIntel: Failed to log unmatched conversation', [
                'error'      => $e->getMessage(),
                'message_id' => $email['id'],
            ]);
        }
    }

    /**
     * Create a CRM offer from AI-detected approval with amount.
     */
    protected function createAiOffer(int $leadId, int $lenderId, string $lenderName, array $aiResult, string $conn, int $userId): ?array
    {
        $amount = (float) $aiResult['offer_amount'];
        if ($amount < 1000) {
            return null;
        }

        try {
            CrmOffer::on($conn)->create([
                'lead_id'        => $leadId,
                'lender_id'      => $lenderId,
                'lender_name'    => $lenderName,
                'status'         => 'pending',
                'offered_amount' => $amount,
                'notes'          => '[AI-detected] ' . ($aiResult['reasoning'] ?? 'Approval detected from lender email'),
                'created_by'     => $userId,
            ]);

            return ['amount' => $amount, 'source' => 'ai'];
        } catch (\Throwable $e) {
            Log::warning('LenderEmailIntel: Failed to create AI offer', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Keyword-based offer detection. Creates a crm_offers entry if an offer is found.
     */
    protected function detectOffer(string $subject, string $body, int $leadId, int $lenderId, string $lenderName, string $conn, int $userId): ?array
    {
        $text = strtolower($subject . ' ' . $body);

        // Must contain at least one approval/offer keyword
        $offerKeywords = ['approved', 'approval', 'offer', 'pre-approved', 'preapproved', 'congratulations'];
        $hasOfferKeyword = false;
        foreach ($offerKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $hasOfferKeyword = true;
                break;
            }
        }

        if (!$hasOfferKeyword) {
            return null;
        }

        $details = [];

        // Extract dollar amount
        if (preg_match('/\$[\s]*([\d,]+(?:\.\d{2})?)\b/', $subject . ' ' . $body, $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
            if ($amount >= 1000) {
                $details['amount'] = $amount;
            }
        }

        // Extract factor rate
        if (preg_match('/factor[\s]*rate[\s:]*(\d+\.?\d*)/i', $body, $m)) {
            $details['factor_rate'] = (float) $m[1];
        }

        // Extract term
        if (preg_match('/(\d+)\s*(?:month|day|week)/i', $body, $m)) {
            $details['term'] = $m[0];
        }

        // Extract daily payment
        if (preg_match('/daily[\s]*(?:payment|debit)[\s:]*\$?([\d,]+(?:\.\d{2})?)/i', $body, $m)) {
            $details['daily_payment'] = (float) str_replace(',', '', $m[1]);
        }

        if (empty($details)) {
            $details['raw_match'] = true;
        }

        // Create crm_offers entry
        try {
            $offerData = [
                'lead_id'      => $leadId,
                'lender_id'    => $lenderId,
                'lender_name'  => $lenderName,
                'status'       => 'pending',
                'notes'        => 'Auto-detected from lender email',
                'created_by'   => $userId,
            ];

            if (isset($details['amount'])) {
                $offerData['offered_amount'] = $details['amount'];
            }
            if (isset($details['factor_rate'])) {
                $offerData['factor_rate'] = $details['factor_rate'];
            }
            if (isset($details['daily_payment'])) {
                $offerData['daily_payment'] = $details['daily_payment'];
            }
            if (isset($details['factor_rate']) && isset($details['amount'])) {
                $offerData['total_payback'] = round($details['amount'] * $details['factor_rate'], 2);
            }

            // Parse term days
            if (isset($details['term'])) {
                if (preg_match('/(\d+)\s*month/i', $details['term'], $tm)) {
                    $offerData['term_days'] = (int) $tm[1] * 30;
                } elseif (preg_match('/(\d+)\s*week/i', $details['term'], $tw)) {
                    $offerData['term_days'] = (int) $tw[1] * 7;
                } elseif (preg_match('/(\d+)\s*day/i', $details['term'], $td)) {
                    $offerData['term_days'] = (int) $td[1];
                }
            }

            CrmOffer::on($conn)->create($offerData);
        } catch (\Throwable $e) {
            Log::warning('LenderEmailIntel: Failed to create offer', ['error' => $e->getMessage()]);
        }

        return $details;
    }

    /**
     * Get paginated conversations, with optional lead/lender/offer filters.
     */
    public function getConversations(string $conn, ?int $leadId = null, ?int $lenderId = null, ?bool $offerDetected = null, ?string $search = null, int $perPage = 20)
    {
        $query = EmailLenderConversation::on($conn)->orderByDesc('conversation_date');

        if ($leadId) {
            $query->where('lead_id', $leadId);
        }
        if ($lenderId) {
            $query->where('lender_id', $lenderId);
        }
        if ($offerDetected !== null) {
            $query->where('offer_detected', $offerDetected);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'LIKE', "%{$search}%")
                  ->orWhere('from_email', 'LIKE', "%{$search}%")
                  ->orWhere('detected_merchant_name', 'LIKE', "%{$search}%")
                  ->orWhere('body_preview', 'LIKE', "%{$search}%")
                  ->orWhere('ai_merchant_name', 'LIKE', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Get all conversations for a specific lead, ordered by date desc.
     */
    public function getConversationsForLead(string $conn, int $leadId)
    {
        return EmailLenderConversation::on($conn)
            ->where('lead_id', $leadId)
            ->orderByDesc('conversation_date')
            ->get();
    }

    /**
     * Get summary stats for dashboard.
     */
    public function getStats(string $conn, int $userId): array
    {
        $base = EmailLenderConversation::on($conn)->where('user_id', $userId);

        $total = (clone $base)->count();
        $offers = (clone $base)->where('offer_detected', true)->count();
        $inbound = (clone $base)->where('direction', 'inbound')->count();
        $outbound = (clone $base)->where('direction', 'outbound')->count();

        $byLender = (clone $base)
            ->select('lender_id', DB::raw('COUNT(*) as count'))
            ->groupBy('lender_id')
            ->pluck('count', 'lender_id')
            ->toArray();

        // Resolve lender names
        $lenderNames = Lender::on($conn)
            ->whereIn('id', array_keys($byLender))
            ->pluck('lender_name', 'id')
            ->toArray();

        $byLenderNamed = [];
        foreach ($byLender as $lid => $count) {
            $byLenderNamed[] = [
                'lender_id'   => $lid,
                'lender_name' => $lenderNames[$lid] ?? "Lender #{$lid}",
                'count'       => $count,
            ];
        }

        return [
            'total_conversations' => $total,
            'offers_detected'     => $offers,
            'inbound'             => $inbound,
            'outbound'            => $outbound,
            'by_lender'           => $byLenderNamed,
        ];
    }

    /**
     * Extract an email address from a "Name <email@example.com>" string.
     */
    protected function extractEmailAddress(string $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        // Handle "Name <email>" format
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            return strtolower(trim($m[1]));
        }

        // Plain email
        $raw = trim($raw);
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return strtolower($raw);
        }

        return null;
    }
}
