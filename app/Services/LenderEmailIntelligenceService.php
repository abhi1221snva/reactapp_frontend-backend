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
use Illuminate\Support\Facades\Log;

class LenderEmailIntelligenceService
{
    protected GmailMailboxService $mailboxService;
    protected LeadEavService $eavService;

    public function __construct()
    {
        $this->mailboxService = new GmailMailboxService();
        $this->eavService = new LeadEavService();
    }

    /**
     * Scan Gmail for emails matching known lender addresses and link to CRM leads.
     *
     * @return array{total_emails_scanned: int, lender_matches: int, conversations_logged: int, offers_detected: int}
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
            return ['total_emails_scanned' => 0, 'lender_matches' => 0, 'conversations_logged' => 0, 'offers_detected' => 0];
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
            }
        }

        return [
            'total_emails_scanned' => $totalScanned,
            'lender_matches'       => $lenderMatches,
            'conversations_logged' => $conversationsLogged,
            'offers_detected'      => $offersDetected,
        ];
    }

    /**
     * Process a single email: match lender, match merchant, log conversation.
     */
    protected function processEmail(array $email, array $emailToLender, array $merchantNames, int $userId, int $clientId, string $conn): ?array
    {
        $messageId = $email['id'] ?? null;
        if (!$messageId) {
            return null;
        }

        // Extract email addresses from from/to fields
        // GmailMailboxService returns ['name' => '...', 'email' => '...'] arrays
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

        // Search for merchant name in subject + body
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

        if (!$matchedLeadId) {
            return null;
        }

        // Dedup check
        $exists = EmailLenderConversation::on($conn)
            ->where('gmail_message_id', $messageId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return ['logged' => false, 'offer_detected' => false];
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

        // Detect offer
        $offerDetected = false;
        $offerDetails = null;
        $offerResult = $this->detectOffer($subject, $bodyPreview, $matchedLeadId, $lender->id, $lender->lender_name, $conn, $userId);
        if ($offerResult) {
            $offerDetected = true;
            $offerDetails = $offerResult;
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
                    'lender_id'        => $lender->id,
                    'gmail_message_id' => $messageId,
                    'direction'        => $direction,
                    'offer_detected'   => $offerDetected,
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
            ]);
        } catch (\Throwable $e) {
            Log::error('LenderEmailIntel: Failed to create conversation', [
                'error'      => $e->getMessage(),
                'message_id' => $messageId,
            ]);
            return ['logged' => false, 'offer_detected' => $offerDetected];
        }

        return ['logged' => true, 'offer_detected' => $offerDetected];
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
                  ->orWhere('body_preview', 'LIKE', "%{$search}%");
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
