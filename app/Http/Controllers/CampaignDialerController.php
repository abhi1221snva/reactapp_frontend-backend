<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TenantAware;
use App\Jobs\DialNextLeadJob;
use App\Model\Client\Campaign;
use App\Model\Client\CampaignAgent;
use App\Model\Client\CampaignLeadQueue;
use App\Model\Client\ExtensionLive;
use App\Services\CampaignDialerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HTTP endpoints for the click-to-call campaign dialer.
 *
 * Routes (all under JWT auth middleware):
 *   POST  /dialer/campaign/{id}/start              — start campaign, populate queue, dispatch first dial
 *   POST  /dialer/campaign/{id}/stop               — pause / stop campaign
 *   POST  /dialer/campaign/{id}/populate           — (re)populate queue from campaign lists
 *   GET   /dialer/campaign/{id}/status             — queue stats + live active calls
 *   GET   /dialer/campaign/{id}/agents             — list assigned agents
 *   POST  /dialer/campaign/{id}/agents             — assign agent { user_id }
 *   DELETE /dialer/campaign/{id}/agents/{userId}   — remove agent
 *   GET   /dialer/agent/{ext}/current-lead         — return lead_id currently on agent's extension
 *   GET   /dialer/lead?lead_id=X                   — return full lead record (for dialer UI)
 *   POST  /dialer/lead/{leadId}/disposition        — save call disposition
 */
class CampaignDialerController extends Controller
{
    use TenantAware;

    // -------------------------------------------------------------------------
    // Campaign lifecycle
    // -------------------------------------------------------------------------

    /**
     * Start a campaign: set status=running, populate queue if empty, dispatch first dial.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $conn     = $this->tenantDb($request);
        $clientId = $this->tenantId($request);

        Campaign::on($conn)->findOrFail($id);

        // Populate queue if this is a fresh start
        $queueCount = CampaignLeadQueue::on($conn)
            ->where('campaign_id', $id)
            ->count();

        if ($queueCount === 0) {
            CampaignLeadQueue::populateFromCampaign($id, $conn);
        }

        Campaign::on($conn)->where('id', $id)->update(['dialer_status' => 'running']);

        // Dispatch first dial immediately
        dispatch((new DialNextLeadJob($id, $clientId, $conn))->onQueue('campaign-dialer'));

        return response()->json([
            'message'     => 'Campaign started',
            'campaign_id' => $id,
            'queue_count' => CampaignLeadQueue::on($conn)->where('campaign_id', $id)->count(),
        ]);
    }

    /**
     * Stop (pause) a running campaign.
     */
    public function stop(Request $request, int $id): JsonResponse
    {
        $conn = $this->tenantDb($request);

        Campaign::on($conn)->where('id', $id)->update(['dialer_status' => 'paused']);

        return response()->json(['message' => 'Campaign paused', 'campaign_id' => $id]);
    }

    /**
     * (Re)populate the lead queue from campaign lists.
     * Safe to call multiple times — uses INSERT IGNORE.
     */
    public function populateQueue(Request $request, int $id): JsonResponse
    {
        $conn = $this->tenantDb($request);

        Campaign::on($conn)->findOrFail($id); // 404 guard

        $count = CampaignLeadQueue::populateFromCampaign($id, $conn);

        return response()->json([
            'message'     => 'Queue populated',
            'campaign_id' => $id,
            'total_leads' => $count,
        ]);
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    /**
     * Campaign queue stats + currently active calls.
     */
    public function status(Request $request, int $id): JsonResponse
    {
        $conn = $this->tenantDb($request);

        $campaign = Campaign::on($conn)->findOrFail($id);

        $stats = DB::connection($conn)
            ->table('campaign_lead_queue')
            ->where('campaign_id', $id)
            ->selectRaw("
                COUNT(*) AS total,
                COALESCE(SUM(status = 'pending'),   0) AS pending,
                COALESCE(SUM(status = 'calling'),   0) AS calling,
                COALESCE(SUM(status = 'completed'), 0) AS completed,
                COALESCE(SUM(status = 'failed'),    0) AS failed
            ")
            ->first();

        $liveCalls = ExtensionLive::on($conn)
            ->where('status', 1)
            ->where('campaign_id', $id)
            ->get(['extension', 'lead_id', 'call_status', 'channel']);

        return response()->json([
            'campaign_id'   => $id,
            'dialer_status' => $campaign->dialer_status,
            'stats'         => $stats,
            'live_calls'    => $liveCalls,
        ]);
    }

    // -------------------------------------------------------------------------
    // Per-agent current call (polled by frontend WebRTC dialer)
    // -------------------------------------------------------------------------

    /**
     * Return the lead_id currently associated with an agent's extension.
     * GET /dialer/agent/{ext}/current-lead
     */
    public function currentLead(Request $request, int $ext): JsonResponse
    {
        $conn = $this->tenantDb($request);

        $live = ExtensionLive::on($conn)
            ->where('extension', $ext)
            ->where('status', 1)
            ->first(['extension', 'lead_id', 'campaign_id', 'call_status']);

        if (!$live || !$live->lead_id) {
            return response()->json(['lead_id' => null, 'call_status' => null]);
        }

        return response()->json([
            'lead_id'     => $live->lead_id,
            'campaign_id' => $live->campaign_id,
            'call_status' => $live->call_status,
        ]);
    }

    // -------------------------------------------------------------------------
    // Lead data (for dialer UI panel)
    // -------------------------------------------------------------------------

    /**
     * Return lead data for the dialer lead panel.
     * Reads from list_data (the auto-dialer's lead store).
     * GET /dialer/lead?lead_id=123
     */
    public function getLead(Request $request): JsonResponse
    {
        $this->validate($request, ['lead_id' => 'required|integer']);

        $conn   = $this->tenantDb($request);
        $leadId = (int) $request->query('lead_id');

        // Lead rows live in list_data; lead_id = list_data.id
        $row = DB::connection($conn)
            ->table('list_data')
            ->where('id', $leadId)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'Lead not found'], 404);
        }

        // Map via list_header: column_name → human label + dialing flag
        $headers = DB::connection($conn)
            ->table('list_header')
            ->where('list_id', $row->list_id)
            ->where('is_deleted', 0)
            ->get(['column_name', 'header', 'is_dialing']);

        $firstName = $lastName = $phone = $email = '';
        $extra = [];

        foreach ($headers as $h) {
            $val = $row->{$h->column_name} ?? '';
            $lbl = strtolower($h->header ?? '');
            $extra[$h->header] = $val;

            if (!$firstName && str_contains($lbl, 'first'))                      $firstName = $val;
            elseif (!$lastName  && str_contains($lbl, 'last'))                   $lastName  = $val;
            if (!$email && (str_contains($lbl, 'email') || str_contains($lbl, 'e-mail'))) $email = $val;
            if ($h->is_dialing) $phone = $val;
        }

        return response()->json(array_merge([
            'id'           => $leadId,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'phone_number' => $phone,
            'email'        => $email,
            'list_id'      => $row->list_id,
        ], $extra));
    }

    // -------------------------------------------------------------------------
    // Disposition
    // -------------------------------------------------------------------------

    /**
     * Save call disposition and notes after a call.
     * POST /dialer/lead/{leadId}/disposition
     */
    public function saveDispo(Request $request, int $leadId): JsonResponse
    {
        $this->validate($request, [
            'disposition_id' => 'required|integer',
            'notes'          => 'nullable|string|max:2000',
            'campaign_id'    => 'nullable|integer',
        ]);

        $conn = $this->tenantDb($request);

        DB::connection($conn)
            ->table('crm_lead_data')
            ->where('id', $leadId)
            ->update([
                'lead_status' => $request->input('disposition_id'),
                'updated_at'  => now(),
            ]);

        if ($request->has('campaign_id')) {
            DB::connection($conn)
                ->table('cdr')
                ->where('lead_id', $leadId)
                ->where('campaign_id', $request->input('campaign_id'))
                ->orderByDesc('id')
                ->limit(1)
                ->update(['disposition_id' => $request->input('disposition_id')]);
        }

        return response()->json(['message' => 'Disposition saved']);
    }

    // -------------------------------------------------------------------------
    // Agent assignment
    // -------------------------------------------------------------------------

    /**
     * List agents assigned to a campaign, with their current status.
     * GET /dialer/campaign/{id}/agents
     */
    public function listAgents(Request $request, int $id): JsonResponse
    {
        $conn = $this->tenantDb($request);

        Campaign::on($conn)->findOrFail($id);

        $assignedUserIds = CampaignAgent::on($conn)
            ->where('campaign_id', $id)
            ->pluck('user_id')
            ->toArray();

        if (empty($assignedUserIds)) {
            return response()->json(['agents' => []]);
        }

        // Fetch user info from master DB (no `name` col — use first_name + last_name)
        $users = DB::connection('master')
            ->table('users')
            ->whereIn('id', $assignedUserIds)
            ->selectRaw('id, CONCAT(first_name, " ", last_name) AS name, email, extension')
            ->get()
            ->keyBy('id');

        // Fetch agent statuses from tenant DB
        $statuses = DB::connection($conn)
            ->table('agent_statuses')
            ->whereIn('user_id', $assignedUserIds)
            ->pluck('status', 'user_id');

        $agents = $users->map(function ($user) use ($statuses) {
            return [
                'user_id'   => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'extension' => $user->extension,
                'status'    => $statuses[$user->id] ?? 'offline',
            ];
        })->values();

        return response()->json(['agents' => $agents]);
    }

    /**
     * Assign an agent to a campaign.
     * POST /dialer/campaign/{id}/agents
     * Body: { user_id: int }
     */
    public function assignAgent(Request $request, int $id): JsonResponse
    {
        $this->validate($request, ['user_id' => 'required|integer']);

        $conn   = $this->tenantDb($request);
        $userId = (int) $request->input('user_id');

        Campaign::on($conn)->findOrFail($id);

        CampaignAgent::on($conn)->firstOrCreate([
            'campaign_id' => $id,
            'user_id'     => $userId,
        ]);

        return response()->json(['message' => 'Agent assigned', 'campaign_id' => $id, 'user_id' => $userId]);
    }

    /**
     * Remove an agent from a campaign.
     * DELETE /dialer/campaign/{id}/agents/{userId}
     */
    public function removeAgent(Request $request, int $id, int $userId): JsonResponse
    {
        $conn = $this->tenantDb($request);

        CampaignAgent::on($conn)
            ->where('campaign_id', $id)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['message' => 'Agent removed', 'campaign_id' => $id, 'user_id' => $userId]);
    }
}
