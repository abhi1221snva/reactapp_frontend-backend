<?php

namespace App\Services;

use App\Jobs\SendLeadByLenderApi;
use App\Model\Client\CrmSendLeadToLender;
use App\Model\Client\Lender;
use App\Model\Client\LenderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
}
