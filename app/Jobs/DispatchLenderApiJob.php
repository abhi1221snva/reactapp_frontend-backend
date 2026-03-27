<?php

namespace App\Jobs;

use App\Model\Client\CrmLenderAPis;
use App\Model\Client\CrmLeadLenderApi;
use App\Services\LenderApiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function __construct(string $clientId, int $leadId, int $lenderId, int $userId = 0)
    {
        $this->clientId = $clientId;
        $this->leadId   = $leadId;
        $this->lenderId = $lenderId;
        $this->userId   = $userId;
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
            clientId:  $this->clientId,
            config:    $config,
            leadData:  $leadData,
            leadId:    $this->leadId,
            lenderId:  $this->lenderId,
            userId:    $this->userId
        );

        // ── Persist extracted application ID if present ────────────────────────
        $appId = $result['parsed']['id_field'] ?? null;
        if ($appId) {
            $existing = CrmLeadLenderApi::on("mysql_{$this->clientId}")
                ->where('lead_id', $this->leadId)
                ->where('lender_id', $this->lenderId)
                ->first();

            if ($existing) {
                $existing->businessID = $appId;
                $existing->save();
            } else {
                DB::connection("mysql_{$this->clientId}")
                    ->table('crm_lead_lender_api')
                    ->insert([
                        'lead_id'         => $this->leadId,
                        'lender_id'       => $this->lenderId,
                        'client_id'       => $this->clientId,
                        'lender_api_type' => $config->type ?: 'generic',
                        'businessID'      => $appId,
                        'created_at'      => Carbon::now(),
                        'updated_at'      => Carbon::now(),
                    ]);
            }
        }

        // ── Update submission record ───────────────────────────────────────────
        $submissionStatus = $result['success'] ? 'submitted' : 'pending';
        DB::connection("mysql_{$this->clientId}")
            ->table('crm_lender_submissions')
            ->where('lead_id', $this->leadId)
            ->where('lender_id', $this->lenderId)
            ->update([
                'submission_status' => $submissionStatus,
                'updated_at'        => Carbon::now(),
            ]);

    }
}
