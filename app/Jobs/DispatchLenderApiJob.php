<?php

namespace App\Jobs;

use App\Model\Client\CrmLenderAPis;
use App\Services\ActivityService;
use App\Services\ApiErrorMapper;
use App\Services\LenderApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function __construct(
        string $clientId,
        int    $leadId,
        int    $lenderId,
        int    $userId      = 0,
        array  $documentIds = []
    ) {
        $this->clientId    = $clientId;
        $this->leadId      = $leadId;
        $this->lenderId    = $lenderId;
        $this->userId      = $userId;
        $this->documentIds = $documentIds;
    }

    public function handle(): void
    {
        // ── Load API config ────────────────────────────────────────────────────
        $config = CrmLenderAPis::on("mysql_{$this->clientId}")
            ->where('crm_lender_id', $this->lenderId)
            ->where('status', true)
            ->first();

        if (!$config) {
            return;
        }

        // ── Resolve lead data ─────────────────────────────────────────────────
        $svc      = new LenderApiService();
        $leadData = $svc->resolveLeadData($this->clientId, $this->leadId);

        if (empty($leadData)) {
            return;
        }

        // ── Execute ────────────────────────────────────────────────────────────
        $result = $svc->dispatch(
            clientId:    $this->clientId,
            config:      $config,
            leadData:    $leadData,
            leadId:      $this->leadId,
            lenderId:    $this->lenderId,
            userId:      $this->userId,
            documentIds: $this->documentIds
        );

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
}
