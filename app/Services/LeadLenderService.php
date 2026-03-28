<?php

namespace App\Services;

use App\Jobs\DispatchLenderApiJob;
use App\Jobs\SendLeadByLenderApi;
use App\Services\EmailService;
use App\Model\Client\CrmLenderSubmission;
use App\Model\Client\CrmSendLeadToLender;
use App\Model\Client\Lender;
use App\Model\Client\LenderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handles all lender-related CRM operations.
 */
class LeadLenderService
{
    /**
     * Get lenders associated with a specific lead.
     */
    public function getLeadLenders(string $clientId, int $leadId): array
    {
        return Lender::on("mysql_{$clientId}")
            ->whereHas('crmSendLeadToLender', function ($q) use ($leadId) {
                $q->where('lead_id', $leadId);
            })
            ->with('crmSendLeadToLender')
            ->get()
            ->toArray();
    }

    /**
     * Get all lender status options for a client.
     */
    public function getLenderStatuses(string $clientId): array
    {
        return LenderStatus::on("mysql_{$clientId}")->get()->toArray();
    }

    /**
     * Update the lender status for all matching lead-lender pairs.
     */
    public function updateLenderStatus(string $clientId, int $lenderId, int $leadId, int $statusId, int $userId): void
    {
        $lenders = CrmSendLeadToLender::on("mysql_{$clientId}")
            ->where('lender_id', $lenderId)
            ->where('lead_id', $leadId)
            ->get();

        foreach ($lenders as $lender) {
            $lender->lender_status_id = $statusId;
            $lender->user_id          = $userId;
            $lender->save();
        }
    }

    /**
     * Add a new lender note or update an existing empty one.
     *
     * @return array{note: CrmSendLeadToLender, created: bool}
     */
    public function addNote(
        string $clientId,
        int    $lenderId,
        int    $leadId,
        string $message,
        int    $userId,
        ?int   $lenderStatus
    ): array {
        $conn     = "mysql_{$clientId}";
        $existing = CrmSendLeadToLender::on($conn)
            ->where('lender_id', $lenderId)
            ->where('lead_id', $leadId)
            ->first();

        $lenderStatusId = $existing ? $existing->lender_status_id : $lenderStatus;

        if ($existing && empty($existing->notes)) {
            $existing->notes            = $message;
            $existing->submitted_date   = Carbon::now();
            $existing->lender_status_id = $lenderStatusId;
            $existing->created_at       = Carbon::now();
            $existing->saveOrFail();

            return ['note' => $existing, 'created' => false];
        }

        $note = new CrmSendLeadToLender();
        $note->setConnection($conn);
        $note->lender_id        = $lenderId;
        $note->lead_id          = $leadId;
        $note->notes            = $message;
        $note->submitted_date   = Carbon::now();
        $note->lender_status_id = $lenderStatusId;
        $note->user_id          = $userId;
        $note->created_at       = Carbon::now();
        $note->saveOrFail();

        return ['note' => $note, 'created' => true];
    }

    /**
     * Get all notes for a lead ordered by most recent first.
     */
    public function getNotesForLead(string $clientId, int $leadId): array
    {
        return CrmSendLeadToLender::on("mysql_{$clientId}")
            ->where('lead_id', $leadId)
            ->orderBy('id', 'desc')
            ->get()
            ->all();
    }

    /**
     * Update the notes field of an existing lender-lead record.
     * Returns null if no matching record found.
     */
    public function updateNote(string $clientId, int $leadId, int $lenderId, string $notes): ?CrmSendLeadToLender
    {
        $note = CrmSendLeadToLender::on("mysql_{$clientId}")
            ->where('lead_id', $leadId)
            ->where('lender_id', $lenderId)
            ->first();

        if (!$note) {
            return null;
        }

        $note->notes = $notes;
        $note->save();

        return $note;
    }

    /**
     * Get lender submission history for a lead.
     */
    public function getSubmissions(string $clientId, int $leadId): array
    {
        $submissions = DB::connection("mysql_{$clientId}")
            ->table('crm_send_lead_to_lender_record as r')
            ->leftJoin('crm_lender as l', 'l.id', '=', DB::raw('CAST(r.lender_id AS UNSIGNED)'))
            ->where('r.lead_id', $leadId)
            ->orderBy('r.created_at', 'desc')
            ->select('r.id', 'r.lead_id', 'r.lender_id', 'r.notes', 'r.lender_status_id', 'r.user_id', 'r.created_at', 'l.lender_name')
            ->get();

        return $submissions->map(function ($s) {
            $arr                   = (array) $s;
            $arr['submitted_date'] = $arr['created_at'];
            return $arr;
        })->values()->toArray();
    }

    /**
     * Record a lead submission to a lender and optionally queue an API job.
     *
     * @return array{id: int, lead_id: int, lender_id: int, lender_name: string, notes: ?string, api_queued: bool}
     * @throws \RuntimeException when the lender is not found
     */
    public function submitToLender(
        string  $clientId,
        int     $leadId,
        int     $lenderId,
        ?string $notes,
        int     $userId
    ): array {
        $conn   = "mysql_{$clientId}";
        $lender = DB::connection($conn)->table('crm_lender')->where('id', $lenderId)->first();

        if (!$lender) {
            throw new \RuntimeException('Lender not found');
        }

        $recordId = DB::connection($conn)
            ->table('crm_send_lead_to_lender_record')
            ->insertGetId([
                'lead_id'    => $leadId,
                'lender_id'  => $lenderId,
                'notes'      => $notes,
                'user_id'    => $userId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        DB::connection($conn)->table('crm_lead_activity')->insert([
            'lead_id'       => $leadId,
            'user_id'       => $userId,
            'activity_type' => 'lender_submitted',
            'subject'       => 'Lead sent to lender: ' . $lender->lender_name,
            'body'          => $notes,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);

        $apiQueued = false;
        if (!empty($lender->api_status) && $lender->api_status == '1') {
            $hasApiCreds = DB::connection($conn)
                ->table('crm_lender_apis')
                ->where('crm_lender_id', $lenderId)
                ->exists();

            if ($hasApiCreds) {
                $jobData = [
                    'lead_id'     => $leadId,
                    'lender_id'   => [['lender_id' => $lenderId]],
                    'lender_name' => [['lender_name' => $lender->lender_name]],
                    'user_id'     => $userId,
                ];
                dispatch(new SendLeadByLenderApi($clientId, $jobData, 'lender_api'))
                    ->onConnection('lender_api_schedule_job');
                $apiQueued = true;
            }
        }

        return [
            'id'          => $recordId,
            'lead_id'     => $leadId,
            'lender_id'   => $lenderId,
            'lender_name' => $lender->lender_name,
            'notes'       => $notes,
            'api_queued'  => $apiQueued,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Enhanced Lender Submission System (crm_lender_submissions)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Submit a funding application to one or more lenders.
     *
     * - Records a row in crm_lender_submissions for each lender.
     * - Sends an email with the PDF attached to each lender's email address.
     * - Logs a lender_submitted activity entry.
     *
     * @param  string   $clientId
     * @param  int      $leadId
     * @param  int[]    $lenderIds
     * @param  int      $userId
     * @param  string   $submitterName  Display name shown in the email body
     * @param  string        $businessName     Business / lead display name for email subject
     * @param  string|null   $pdfStoragePath   Relative storage path (legacy single PDF)
     * @param  string|null   $notes            Cover note
     * @param  array         $documentIds      IDs from crm_documents to attach
     * @param  string|null   $emailSubject     Custom subject (overrides default)
     * @param  string|null   $emailHtmlOverride Pre-rendered HTML body (overrides blade template)
     * @return array{submitted: int[], failed: int[], records: array<int, array>}
     *
     * Routing is automatic per lender:
     *   - lender.api_status = 1  AND  active crm_lender_apis row  →  DispatchLenderApiJob
     *   - otherwise                                                →  email
     */
    public function submitApplication(
        string  $clientId,
        int     $leadId,
        array   $lenderIds,
        int     $userId,
        string  $submitterName,
        string  $businessName,
        ?string $pdfStoragePath      = null,
        ?string $notes               = null,
        array   $documentIds         = [],
        ?string $emailSubject        = null,
        ?string $emailHtmlOverride   = null
    ): array {
        $conn      = "mysql_{$clientId}";
        $submitted = [];
        $failed    = [];
        $records   = [];

        // ── Resolve attachments ──────────────────────────────────────────────────
        $attachments = [];

        // Legacy single PDF path
        if ($pdfStoragePath) {
            $abs = storage_path('app/public/' . ltrim($pdfStoragePath, '/'));
            if (file_exists($abs)) {
                $attachments[] = $abs;
            }
        }

        // Documents from crm_documents table
        $docs = collect();
        if (!empty($documentIds)) {
            $docs = DB::connection($conn)
                ->table('crm_documents')
                ->whereIn('id', $documentIds)
                ->get(['id', 'file_path', 'file_name', 'document_type', 'document_name']);

            foreach ($docs as $doc) {
                if (empty($doc->file_path)) continue;

                $abs = null;

                // Strategy 1: already an absolute filesystem path
                if (str_starts_with($doc->file_path, '/') && !str_starts_with($doc->file_path, '//')) {
                    if (is_file($doc->file_path)) {
                        $abs = $doc->file_path;
                    }
                }

                // Strategy 2: full URL or URL-path  (e.g. https://domain/storage/crm_documents/…)
                if (!$abs) {
                    $urlPath  = parse_url($doc->file_path, PHP_URL_PATH) ?? $doc->file_path;
                    $relative = ltrim($urlPath, '/');

                    // Strip leading "storage/" — Laravel public disk symlink
                    if (str_starts_with($relative, 'storage/')) {
                        $relative = substr($relative, strlen('storage/'));
                    }

                    $candidate = rtrim(storage_path('app/public'), '/') . '/' . $relative;
                    if (is_file($candidate)) {
                        $abs = $candidate;
                    }
                }

                // Strategy 3: use Storage::disk('public') directly with the relative path
                if (!$abs) {
                    $urlPath2  = parse_url($doc->file_path, PHP_URL_PATH) ?? $doc->file_path;
                    $rel2 = ltrim($urlPath2, '/');
                    // strip leading "storage/" if present
                    if (str_starts_with($rel2, 'storage/')) {
                        $rel2 = substr($rel2, strlen('storage/'));
                    }
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($rel2)) {
                        $abs = \Illuminate\Support\Facades\Storage::disk('public')->path($rel2);
                    }
                }

                if ($abs) {
                    $attachments[] = $abs;
                } else {
                    Log::warning("LeadLenderService: document #{$doc->id} not found on disk", [
                        'original'  => $doc->file_path,
                        'candidate' => $candidate ?? null,
                    ]);
                }
            }
        }

        // ── Resolve submission SMTP ──────────────────────────────────────────────
        $emailSvc = null;
        $resolvers = [
            fn() => EmailService::forClient((int) $clientId, 'submission'),
            fn() => EmailService::forClient((int) $clientId, 'notification'),
            fn() => EmailService::forClientAny((int) $clientId),
            fn() => EmailService::systemDefault(),
        ];
        foreach ($resolvers as $resolver) {
            try {
                $emailSvc = $resolver();
                break;
            } catch (\Throwable) {
                // try next
            }
        }
        if (!$emailSvc) {
            Log::warning("submitApplication: no email config available for client {$clientId}");
        }

        // ── Render email body once ───────────────────────────────────────────────
        $emailHtml = $emailHtmlOverride ?? view('emails.lender_application', [
            'businessName' => $businessName,
            'senderName'   => $submitterName,
            'customNote'   => $notes,
        ])->render();

        $subject = $emailSubject ?: "New Funding Application — {$businessName}";

        // Build a list of doc labels for notes
        $docLabels = [];
        foreach ($docs as $docItem) {
            $label = $docItem->document_type ?: ($docItem->document_name ?: basename($docItem->file_path ?? ''));
            if ($label) $docLabels[] = $label;
        }

        foreach ($lenderIds as $lenderId) {
            $lender = DB::connection($conn)->table('crm_lender')->where('id', $lenderId)->first();
            if (!$lender) {
                $failed[] = $lenderId;
                continue;
            }

            try {
                $now        = Carbon::now();
                $dateLabel  = $now->format('Y-m-d H:i');
                $docsStr    = !empty($docLabels)
                    ? implode(', ', $docLabels)
                    : null;
                $autoNote   = $docsStr
                    ? "[{$dateLabel}] {$submitterName} sent: {$docsStr}"
                    : "[{$dateLabel}] {$submitterName} submitted application (no documents)";

                $existingSub = DB::connection($conn)
                    ->table('crm_lender_submissions')
                    ->where('lead_id', $leadId)
                    ->where('lender_id', $lenderId)
                    ->first();

                // ── Auto-route: API if lender has api_status=1 + active config, else email ─
                $apiConfig = null;
                if (!empty($lender->api_status) && $lender->api_status == '1') {
                    $apiConfig = DB::connection($conn)
                        ->table('crm_lender_apis')
                        ->where('crm_lender_id', $lenderId)
                        ->where('status', true)
                        ->first();
                }

                $actualType = $apiConfig ? 'api' : 'normal';

                // ── Persist / update submission record ───────────────────────────────
                if ($existingSub) {
                    $oldNotes = $existingSub->notes ?? '';
                    $newNotes = $oldNotes ? "{$autoNote}\n\n{$oldNotes}" : $autoNote;

                    DB::connection($conn)
                        ->table('crm_lender_submissions')
                        ->where('id', $existingSub->id)
                        ->update([
                            'submission_status' => 'submitted',
                            'submission_type'   => $actualType,
                            'application_pdf'   => $pdfStoragePath ?? $existingSub->application_pdf,
                            'submitted_by'      => $userId,
                            'submitted_at'      => $now,
                            'notes'             => $newNotes,
                            'updated_at'        => $now,
                        ]);
                    $subId = $existingSub->id;
                } else {
                    $initNotes = $notes ? "{$autoNote}\n\n{$notes}" : $autoNote;
                    $subId = DB::connection($conn)->table('crm_lender_submissions')->insertGetId([
                        'lead_id'           => $leadId,
                        'lender_id'         => $lenderId,
                        'lender_name'       => $lender->lender_name,
                        'lender_email'      => $lender->email,
                        'application_pdf'   => $pdfStoragePath,
                        'submission_status' => 'submitted',
                        'submission_type'   => $actualType,
                        'response_status'   => 'pending',
                        'notes'             => $initNotes,
                        'submitted_by'      => $userId,
                        'submitted_at'      => $now,
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ]);
                }

                // ── Dispatch based on route ───────────────────────────────────────────
                if ($apiConfig) {
                    // API submission
                    dispatch(new DispatchLenderApiJob($clientId, $leadId, $lenderId, $userId))
                        ->onConnection('redis')
                        ->onQueue('default');

                    DB::connection($conn)->table('crm_lead_activity')->insert([
                        'lead_id'       => $leadId,
                        'user_id'       => $userId,
                        'activity_type' => 'lender_submitted',
                        'subject'       => 'Application submitted via API to: ' . $lender->lender_name,
                        'body'          => $autoNote,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                } else {
                    // Email submission
                    $emailTargets = array_values(array_filter([
                        $lender->email,
                        $lender->secondary_email  ?? null,
                        $lender->secondary_email2 ?? null,
                        $lender->secondary_email3 ?? null,
                        $lender->secondary_email4 ?? null,
                    ]));

                    if ($emailSvc && !empty($emailTargets)) {
                        $primaryTo = array_shift($emailTargets);
                        try {
                            $emailSvc->send(
                                to:          trim($primaryTo),
                                subject:     $subject,
                                html:        $emailHtml,
                                attachments: $attachments,
                                cc:          array_map('trim', $emailTargets),
                            );
                        } catch (\Throwable $mailEx) {
                            Log::warning("LenderApplicationMail failed for lender {$lenderId} → {$primaryTo}: " . $mailEx->getMessage());
                        }
                    }

                    DB::connection($conn)->table('crm_lead_activity')->insert([
                        'lead_id'       => $leadId,
                        'user_id'       => $userId,
                        'activity_type' => 'lender_submitted',
                        'subject'       => 'Application submitted via email to: ' . $lender->lender_name,
                        'body'          => $autoNote,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }

                $submitted[] = $lenderId;
                $records[$lenderId] = [
                    'id'                => $subId,
                    'lender_id'         => $lenderId,
                    'lender_name'       => $lender->lender_name,
                    'submission_status' => 'submitted',
                    'submission_type'   => $actualType,
                    'response_status'   => ($existingSub->response_status ?? 'pending'),
                    'submitted_at'      => $now->toDateTimeString(),
                    'notes'             => ($existingSub->notes ?? null),
                ];
            } catch (\Throwable $e) {
                Log::error("submitApplication failed for lender {$lenderId}: " . $e->getMessage());
                $failed[] = $lenderId;
            }
        }

        return compact('submitted', 'failed', 'records');
    }

    /**
     * Fetch enhanced lender submissions (from crm_lender_submissions) for a lead.
     */
    public function getEnhancedSubmissions(string $clientId, int $leadId): array
    {
        return DB::connection("mysql_{$clientId}")
            ->table('crm_lender_submissions')
            ->where('lead_id', $leadId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->values()
            ->toArray();
    }

    /**
     * Update lender response on a submission record.
     *
     * @return array|null  Updated record or null if not found
     */
    public function updateSubmissionResponse(
        string  $clientId,
        int     $leadId,
        int     $submissionId,
        string  $responseStatus,
        ?string $responseNote  = null,
        ?string $submissionStatus = null,
        int     $userId = 0
    ): ?array {
        $conn = "mysql_{$clientId}";
        $row  = DB::connection($conn)
            ->table('crm_lender_submissions')
            ->where('id', $submissionId)
            ->where('lead_id', $leadId)
            ->first();

        if (!$row) {
            return null;
        }

        $now    = Carbon::now();
        $update = [
            'response_status'       => $responseStatus,
            'response_note'         => $responseNote,
            'response_received_at'  => $now,
            'updated_at'            => $now,
        ];

        if ($submissionStatus) {
            $update['submission_status'] = $submissionStatus;
        }

        DB::connection($conn)->table('crm_lender_submissions')
            ->where('id', $submissionId)
            ->update($update);

        // Activity log
        DB::connection($conn)->table('crm_lead_activity')->insert([
            'lead_id'       => $leadId,
            'user_id'       => $userId,
            'activity_type' => 'lender_response',
            'subject'       => 'Lender response updated: ' . ($row->lender_name ?? "Lender #{$row->lender_id}"),
            'body'          => "Response: {$responseStatus}" . ($responseNote ? " — {$responseNote}" : ''),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // ── Sync linked approval record ────────────────────────────────────────
        $lenderName    = $row->lender_name ?? "Lender #{$row->lender_id}";
        $approvalStage = $lenderName;

        $existingApproval = DB::connection($conn)->table('crm_lead_approvals')
            ->where('lead_id', $leadId)
            ->where('approval_type', 'lender_submission')
            ->where('approval_stage', $approvalStage)
            ->first();

        if ($responseStatus === 'approved') {
            if ($existingApproval) {
                DB::connection($conn)->table('crm_lead_approvals')
                    ->where('id', $existingApproval->id)
                    ->update([
                        'status'      => 'approved',
                        'reviewed_by' => $userId ?: null,
                        'reviewed_at' => $now,
                        'review_note' => $responseNote,
                        'updated_at'  => $now,
                    ]);
            } else {
                DB::connection($conn)->table('crm_lead_approvals')->insert([
                    'lead_id'        => $leadId,
                    'requested_by'   => $userId ?: 1,
                    'reviewed_by'    => $userId ?: null,
                    'approval_type'  => 'lender_submission',
                    'approval_stage' => $approvalStage,
                    'status'         => 'approved',
                    'request_note'   => "Approved by {$lenderName}",
                    'review_note'    => $responseNote,
                    'reviewed_at'    => $now,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        } elseif ($existingApproval && $existingApproval->status !== 'withdrawn') {
            // Sync status: declined → declined, anything else → pending
            $mappedStatus = ($responseStatus === 'declined') ? 'declined' : 'pending';
            DB::connection($conn)->table('crm_lead_approvals')
                ->where('id', $existingApproval->id)
                ->update([
                    'status'      => $mappedStatus,
                    'review_note' => $responseNote,
                    'reviewed_by' => $userId ?: null,
                    'reviewed_at' => $now,
                    'updated_at'  => $now,
                ]);
        }

        return array_merge((array) $row, $update);
    }
}
