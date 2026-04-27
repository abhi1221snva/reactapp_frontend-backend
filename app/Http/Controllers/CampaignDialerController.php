<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TenantAware;
use App\Jobs\DialNextLeadJob;
use App\Model\Client\AgentStatus;
use App\Model\Campaign as CampaignModel;
use App\Model\Client\Campaign;
use App\Model\Client\CampaignAgent;
use App\Model\Client\CampaignLeadQueue;
use App\Model\Client\ExtensionLive;
use App\Services\AsteriskAmiService;
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

        CampaignModel::authorizeAccess($id, $request);
        Campaign::on($conn)->findOrFail($id);

        // Populate queue if this is a fresh start
        $queueCount = CampaignLeadQueue::on($conn)
            ->where('campaign_id', $id)
            ->count();

        if ($queueCount === 0) {
            CampaignLeadQueue::populateFromCampaign($id, $conn);
        }

        Campaign::on($conn)->where('id', $id)->update(['dialer_status' => 'running']);

        // Ensure all assigned agents have an "available" status so the dialer can pick them up.
        // Without this, getIdleAgentExtension() finds no idle agents and the first call never fires.
        $assignedUserIds = CampaignAgent::on($conn)
            ->where('campaign_id', $id)
            ->pluck('user_id');

        foreach ($assignedUserIds as $userId) {
            $existing = AgentStatus::on($conn)->where('user_id', $userId)->first();
            // Only set to available if they have no status yet or are offline
            if (!$existing || $existing->status === AgentStatus::OFFLINE) {
                AgentStatus::setStatus((int) $userId, AgentStatus::AVAILABLE, $id, $clientId, $conn);
            }
        }

        // Reset any stale extension_live entries for this campaign
        ExtensionLive::on($conn)
            ->where('campaign_id', $id)
            ->update(['status' => 0, 'lead_id' => null, 'call_status' => null, 'channel' => null, 'conf_room' => null, 'customer_channel' => null]);

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

        CampaignModel::authorizeAccess($id, $request);
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

        CampaignModel::authorizeAccess($id, $request);
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
    // Lead CDR / activity timeline
    // -------------------------------------------------------------------------

    /**
     * Return CDR records for a specific lead.
     * GET /dialer/lead/{leadId}/cdr
     */
    public function getLeadCdr(Request $request, int $leadId): JsonResponse
    {
        $conn = $this->tenantDb($request);

        $records = DB::connection($conn)
            ->table('cdr')
            ->leftJoin('disposition', 'cdr.disposition_id', '=', 'disposition.id')
            ->where('cdr.lead_id', $leadId)
            ->orderByDesc('cdr.start_time')
            ->limit(50)
            ->get([
                'cdr.id',
                'cdr.extension',
                'cdr.route',
                'cdr.type',
                'cdr.number',
                'cdr.duration',
                'cdr.start_time',
                'cdr.end_time',
                'cdr.call_recording',
                'cdr.campaign_id',
                'cdr.disposition_id',
                'cdr.lead_id',
                'disposition.title as disposition_title',
            ]);

        return response()->json(['data' => $records]);
    }

    // -------------------------------------------------------------------------
    // Persistent Conference: hang up customer only (disposition mode)
    // -------------------------------------------------------------------------

    /**
     * Hang up only the customer channel — agent stays in conference for disposition.
     *
     * POST /dialer/campaign/{id}/hangup-customer
     */
    public function hangupCustomer(Request $request, int $id): JsonResponse
    {
        $conn     = $this->tenantDb($request);
        $clientId = $this->tenantId($request);

        CampaignModel::authorizeAccess($id, $request);

        $userId = $request->auth->id ?? 0;
        $user   = DB::connection('master')
            ->table('users')
            ->where('id', $userId)
            ->first(['extension', 'alt_extension']);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $agentExt = (int) ($user->alt_extension ?: $user->extension);
        if (!$agentExt) {
            return response()->json(['error' => 'No extension configured'], 422);
        }

        try {
            $ami     = new AsteriskAmiService();
            $service = new CampaignDialerService($ami);
            $result  = $service->hangupCustomerOnly($id, $agentExt, $clientId, $conn);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal error: ' . $e->getMessage(),
            ], 500);
        }

        if ($result['status'] === 'error') {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        return response()->json([
            'success'          => true,
            'message'          => 'Customer channel disconnected — ready for disposition',
            'previous_lead_id' => $result['previous_lead_id'] ?? null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Persistent Conference: hang up customer, dial next lead
    // -------------------------------------------------------------------------

    /**
     * Hang up only the customer channel while keeping the agent in the conference.
     * Immediately dials the next lead into the same conf room.
     *
     * POST /dialer/campaign/{id}/next-customer
     */
    public function nextCustomer(Request $request, int $id): JsonResponse
    {
        $conn     = $this->tenantDb($request);
        $clientId = $this->tenantId($request);

        CampaignModel::authorizeAccess($id, $request);

        // Resolve agent extension from JWT user
        $userId = $request->auth->id ?? 0;
        $user   = DB::connection('master')
            ->table('users')
            ->where('id', $userId)
            ->first(['extension', 'alt_extension']);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $agentExt = (int) ($user->alt_extension ?: $user->extension);
        if (!$agentExt) {
            return response()->json(['error' => 'No extension configured'], 422);
        }

        // Debug: log extension_live state before attempting
        $liveRow = \App\Model\Client\ExtensionLive::on($conn)
            ->where('extension', $agentExt)
            ->first();
        \Log::info("nextCustomer: ext={$agentExt} conn={$conn} live=" . json_encode($liveRow));

        try {
            $ami     = new AsteriskAmiService();
            $service = new CampaignDialerService($ami);
            $result  = $service->hangupCustomerAndDialNext($id, $agentExt, $clientId, $conn);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal error: ' . $e->getMessage(),
            ], 500);
        }

        if ($result['status'] === 'error') {
            \Log::warning("nextCustomer: error for ext={$agentExt}: {$result['message']}");
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        if ($result['status'] === 'no_more_leads') {
            return response()->json([
                'success'   => true,
                'status'    => 'no_more_leads',
                'message'   => 'No more leads in queue',
                'lead'      => null,
            ]);
        }

        $lead = $result['lead'] ?? [];

        return response()->json(array_merge([
            'success'      => true,
            'status'       => 'next_lead',
            'message'      => 'Next customer being dialed',
        ], $lead));
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

        CampaignModel::authorizeAccess($id, $request);
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
            ->selectRaw('id, CONCAT(first_name, " ", last_name) AS name, email, extension, alt_extension')
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
                'extension' => $user->alt_extension ?: $user->extension,
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

        $conn     = $this->tenantDb($request);
        $clientId = $this->tenantId($request);
        $userId   = (int) $request->input('user_id');

        CampaignModel::authorizeAccess($id, $request);
        $campaign = Campaign::on($conn)->findOrFail($id);

        CampaignAgent::on($conn)->firstOrCreate([
            'campaign_id' => $id,
            'user_id'     => $userId,
        ]);

        // Set agent to available so the dialer can pick them up immediately
        $existing = AgentStatus::on($conn)->where('user_id', $userId)->first();
        if (!$existing || $existing->status === AgentStatus::OFFLINE) {
            AgentStatus::setStatus($userId, AgentStatus::AVAILABLE, $id, $clientId, $conn);
        }

        // If campaign is already running, dispatch a dial attempt in case this was the missing agent
        if ($campaign->dialer_status === 'running') {
            dispatch(
                (new DialNextLeadJob($id, $clientId, $conn))
                    ->onQueue('campaign-dialer')
                    ->delay(now()->addSeconds(1))
            );
        }

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
