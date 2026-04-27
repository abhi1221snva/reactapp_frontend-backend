<?php

namespace App\Services;

use App\Model\Client\Campaign;
use App\Model\Client\CampaignLeadQueue;
use App\Model\Client\ExtensionLive;
use App\Model\Client\AgentStatus;
use App\Model\Client\CampaignAgent;
use App\Services\CallerIdResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

/**
 * Orchestrates the agent-first click-to-call campaign dialer.
 *
 * Flow:
 *  1. dialNextLead()  — pick next queued lead, find idle agent, AMI originate → agent
 *  2. handleAgentAnswered()  — agent picked up + entered ConfBridge; second originate → customer
 *  3. handleCallBridged()    — customer answered, joined same conference room; push lead data to UI
 *  4. handleCallHangup()     — call ended (with dedup); reset extension, log CDR, dispatch next
 *  5. handleAgentNoAnswer()  — agent timeout; retry or move to failed
 *  6. handleCustomerOriginateFailed() — customer didn't answer; kick conf, notify agent
 */
class CampaignDialerService
{
    // Max retries before marking a lead as failed
    const DEFAULT_MAX_RETRIES = 3;
    // Seconds to wait before retrying a lead after agent no-answer
    const DEFAULT_RETRY_DELAY = 300;
    // Agent ring timeout (ms sent to AMI)
    const DEFAULT_AGENT_TIMEOUT_MS = 30000;

    public function __construct(
        protected AsteriskAmiService $ami,
        protected ?CallerIdResolverService $callerIdResolver = null
    ) {
        $this->callerIdResolver ??= new CallerIdResolverService();
    }

    // -------------------------------------------------------------------------
    // Core: pick next lead and originate
    // -------------------------------------------------------------------------

    /**
     * Pick the next dialable lead for a campaign and initiate the agent-first call.
     *
     * @param  int     $campaignId
     * @param  int     $clientId       tenant client_id (used for DB connection + AMI lookup)
     * @param  string  $dbConnection   tenant DB connection name
     * @return CampaignLeadQueue|null  the queue entry being dialed, or null if nothing to dial
     */
    public function dialNextLead(int $campaignId, int $clientId, string $dbConnection): ?CampaignLeadQueue
    {
        $campaign = Campaign::on($dbConnection)->find($campaignId);

        if (!$campaign || $campaign->dialer_status !== 'running') {
            return null;
        }

        // Find an idle agent assigned to this campaign
        $agentExt = $this->getIdleAgentExtension($campaignId, $dbConnection);

        if (!$agentExt) {
            Log::info("CampaignDialer: no idle agents for campaign $campaignId");
            return null;
        }

        // Pick next lead — transaction ensures only one worker grabs it
        $entry = DB::connection($dbConnection)->transaction(function () use ($campaignId, $dbConnection) {
            return CampaignLeadQueue::nextDialable($campaignId, $dbConnection);
        });

        if (!$entry) {
            $this->checkCampaignCompletion($campaignId, $campaign, $dbConnection);
            return null;
        }

        // Mark as currently being called
        $entry->update([
            'status'    => CampaignLeadQueue::STATUS_CALLING,
            'attempts'  => DB::raw('attempts + 1'),
            'called_at' => now(),
        ]);

        // Load lead phone number from list_data
        // The dialing column (e.g. option_3) is flagged via list_header.is_dialing=1
        $leadRow = DB::connection($dbConnection)
            ->table('list_data')
            ->where('id', $entry->lead_id)
            ->first();

        $phone = null;
        if ($leadRow) {
            $dialCol = DB::connection($dbConnection)
                ->table('list_header')
                ->where('list_id', $leadRow->list_id)
                ->where('is_dialing', 1)
                ->value('column_name');

            $phone = $dialCol ? ($leadRow->$dialCol ?? null) : null;
        }

        // Build a minimal lead object compatible with rest of the method
        $lead = $leadRow ? (object)[
            'id'           => $leadRow->id,
            'phone_number' => $phone,
            'first_name'   => $leadRow->option_1 ?? '',
            'last_name'    => $leadRow->option_2 ?? '',
        ] : null;

        if (!$lead || empty($lead->phone_number)) {
            Log::warning("CampaignDialer: lead {$entry->lead_id} has no phone — skipping");
            $entry->update(['status' => CampaignLeadQueue::STATUS_SKIPPED]);

            // Guard against deep recursion — skip up to 50 phoneless leads, then bail
            static $skipCount = 0;
            $skipCount++;
            if ($skipCount >= 50) {
                $skipCount = 0;
                Log::warning("CampaignDialer: 50 consecutive phoneless leads in campaign {$campaignId} — stopping");
                return null;
            }

            $result = $this->dialNextLead($campaignId, $clientId, $dbConnection);
            $skipCount = 0;
            return $result;
        }

        // Generate a unique conference room name for this call
        $confRoom = "camp_{$campaignId}_{$entry->lead_id}_{$agentExt}_" . time();

        // Pre-populate extension_live so frontend can see "ringing" immediately
        ExtensionLive::markLive(
            $agentExt,
            $campaignId,
            $entry->lead_id,
            null,
            ExtensionLive::CALL_STATUS_RINGING,
            $confRoom,
            $dbConnection
        );

        // Update agent status
        AgentStatus::setStatus(
            $this->extensionToUserId($agentExt, $dbConnection),
            AgentStatus::ON_CALL,
            $campaignId,
            $clientId,
            $dbConnection
        );

        // Build ActionID so we can match OriginateResponse events back to this call
        $actionId = "camp_{$campaignId}_lead_{$entry->lead_id}_ext_{$agentExt}_" . time();

        // Resolve outbound caller ID based on campaign's caller_id strategy
        // (custom / area_code / area_code_random / area_code_3 / area_code_4 / area_code_5)
        $resolvedCli = $this->callerIdResolver->resolve(
            $dbConnection,
            $campaignId,
            $lead->phone_number
        ) ?? '0000000000';

        // Resolve the outbound SIP trunk and dial prefix for this client/campaign
        $trunkConfig = $this->resolveTrunkConfig($campaignId, $clientId, $dbConnection);
        $trunk       = $trunkConfig['trunk'];
        $dialPrefix  = $trunkConfig['prefix'];

        // Sanitise the customer phone number (strip non-digit chars)
        $cleanPhone = $this->sanitizePhone($lead->phone_number);

        // Ensure AMI is connected for this client
        if (!$this->ami->isConnected()) {
            $this->ami->connectForClient($clientId);
        }

        // Originate: ring the AGENT first. When agent answers, dialplan puts them
        // into a ConfBridge room. Backend then originates a second call to the customer.
        $this->ami->originate([
            'Channel'  => "PJSIP/{$agentExt}",
            'Context'  => 'campaign-conf-agent',
            'Exten'    => 's',
            'Priority' => '1',
            // CallerID shown to AGENT when their WebPhone rings (internal label)
            'CallerID' => '"Campaign Call" <' . $resolvedCli . '>',
            'Timeout'  => self::DEFAULT_AGENT_TIMEOUT_MS,
            'ActionID' => $actionId,
            'Variable' => [
                "LEAD_ID={$entry->lead_id}",
                "CAMPAIGN_ID={$campaignId}",
                "CLIENT_ID={$clientId}",
                "DB_CONN={$dbConnection}",
                "AGENT_EXT={$agentExt}",
                "CUSTOMER_NUM={$cleanPhone}",
                "CALLER_ID_NUM={$resolvedCli}",
                "TRUNK={$trunk}",
                "DIAL_PREFIX={$dialPrefix}",
                "CALL_TIMEOUT=60",
                "CONF_ROOM={$confRoom}",
            ],
        ]);

        Log::info("CampaignDialer: originated to agent {$agentExt} for lead {$entry->lead_id} (campaign {$campaignId})");

        return $entry;
    }

    // -------------------------------------------------------------------------
    // AMI event handlers (called by AmiListenCommand)
    // -------------------------------------------------------------------------

    /**
     * Agent answered the incoming SIP call and entered the ConfBridge room.
     * Now send a second AMI Originate to dial the customer into the same room.
     */
    public function handleAgentAnswered(array $event, string $dbConnection, int $clientId): void
    {
        $leadId      = (int) ($event['LeadID'] ?? 0);
        $agentExt    = (int) ($event['AgentExt'] ?? 0);
        $channel     = $event['Channel'] ?? null;
        $confRoom    = $event['ConfRoom'] ?? null;
        $customerNum = $event['CustomerNum'] ?? null;
        $callerIdNum = $event['CallerIdNum'] ?? null;
        $trunk       = $event['Trunk'] ?? '';
        $dialPrefix  = $event['DialPrefix'] ?? '';
        $callTimeout = (int) ($event['CallTimeout'] ?? 60);
        $campaignId  = (int) ($event['CampaignID'] ?? 0);

        if (!$leadId || !$agentExt || !$confRoom || !$customerNum) {
            Log::warning("CampaignDialer: AgentAnswered missing required fields", $event);
            return;
        }

        // If trunk wasn't passed in the event, re-resolve from DB
        if (empty($trunk)) {
            $trunkConfig = $this->resolveTrunkConfig($campaignId, $clientId, $dbConnection);
            $trunk      = $trunkConfig['trunk'];
            $dialPrefix = $trunkConfig['prefix'];
        }

        // Update extension_live: agent is connected, waiting for customer
        ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->where('lead_id', $leadId)
            ->update([
                'status'      => 1,
                'channel'     => $channel,
                'call_status' => ExtensionLive::CALL_STATUS_CONNECTED,
                'conf_room'   => $confRoom,
            ]);

        // Ensure AMI is connected
        if (!$this->ami->isConnected()) {
            $this->ami->connectForClient($clientId);
        }

        // Build outbound channel with correct trunk and prefix
        $outboundChannel = $this->buildOutboundChannel($customerNum, $trunk, $dialPrefix);

        // Second originate: dial the customer into the same conference room
        $custActionId = "cust_{$confRoom}_" . time();

        $this->ami->originate([
            'Channel'  => $outboundChannel,
            'Context'  => 'campaign-conf-customer-join',
            'Exten'    => 's',
            'Priority' => '1',
            'CallerID' => $callerIdNum ?: '0000000000',
            'Timeout'  => $callTimeout * 1000,
            'ActionID' => $custActionId,
            'Variable' => [
                "LEAD_ID={$leadId}",
                "CAMPAIGN_ID={$campaignId}",
                "CLIENT_ID=" . ($event['ClientID'] ?? 0),
                "DB_CONN={$dbConnection}",
                "AGENT_EXT={$agentExt}",
                "CUSTOMER_NUM={$customerNum}",
                "CONF_ROOM={$confRoom}",
            ],
        ]);

        Log::info("CampaignDialer: agent {$agentExt} answered, lead {$leadId} — originating customer call to {$customerNum} via {$outboundChannel} (room: {$confRoom})");
    }

    /**
     * Customer answered. Agent and customer are now bridged.
     * Push real-time event to agent's browser with the lead_id.
     */
    public function handleCallBridged(array $event, string $dbConnection, int $clientId): void
    {
        $leadId    = (int) ($event['LeadID'] ?? 0);
        $agentExt  = (int) ($event['AgentExt'] ?? 0);
        $campaignId = (int) ($event['CampaignID'] ?? 0);

        if (!$leadId || !$agentExt) {
            return;
        }

        ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->where('lead_id', $leadId)
            ->update([
                'call_status'     => ExtensionLive::CALL_STATUS_BRIDGED,
                'call_started_at' => now(),
            ]);

        // Push to agent's browser via Pusher so the lead panel appears instantly
        $this->pushToAgent($agentExt, 'call.bridged', [
            'lead_id'     => $leadId,
            'campaign_id' => $campaignId,
            'extension'   => $agentExt,
        ], $clientId);

        Log::info("CampaignDialer: call bridged — agent {$agentExt}, lead {$leadId}");
    }

    /**
     * Call hung up (by agent or customer). Log CDR, reset extension, dispatch next dial.
     *
     * Conference model: both agent and customer legs fire CallHangup with a `Who` field.
     * Dedup guard: if extension_live is already idle, skip (the other leg already processed it).
     * If customer hung up first, kick all from the conference to tear down the agent leg.
     */
    public function handleCallHangup(array $event, string $dbConnection, int $clientId): void
    {
        $leadId      = (int) ($event['LeadID'] ?? 0);
        $agentExt    = (int) ($event['AgentExt'] ?? 0);
        $campaignId  = (int) ($event['CampaignID'] ?? 0);
        $hangupCause = (int) ($event['HangupCause'] ?? 0);
        $who         = $event['Who'] ?? 'unknown';
        $confRoom    = $event['ConfRoom'] ?? null;

        if (!$leadId || !$agentExt) {
            return;
        }

        // Dedup guard: skip if extension_live is already idle (the other leg already ran cleanup)
        $live = ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->first();

        if (!$live || !$live->status) {
            Log::debug("CampaignDialer: hangup dedup — agent {$agentExt} already idle (Who: {$who})");
            return;
        }

        if ($who === 'customer') {
            // ── Persistent conference: customer hung up, agent stays in conf ──
            // Dedup: if lead_id was already cleared by hangupCustomerOnly(), skip
            if (!$live->lead_id || (int) $live->lead_id !== $leadId) {
                Log::debug("CampaignDialer: customer hangup already processed for lead {$leadId} (ext {$agentExt})");
                return;
            }

            // Write CDR and mark lead completed
            $this->writeCdr($agentExt, $leadId, $campaignId, $live, $dbConnection);

            CampaignLeadQueue::on($dbConnection)
                ->where('campaign_id', $campaignId)
                ->where('lead_id', $leadId)
                ->where('status', CampaignLeadQueue::STATUS_CALLING)
                ->update([
                    'status'       => CampaignLeadQueue::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

            // Clear customer info but keep agent in conference
            ExtensionLive::on($dbConnection)
                ->where('extension', $agentExt)
                ->update([
                    'lead_id'          => null,
                    'customer_channel' => null,
                    'call_status'      => ExtensionLive::CALL_STATUS_CONNECTED,
                ]);

            // Push event to agent's browser for disposition
            $this->pushToAgent($agentExt, 'call.ended', [
                'lead_id'      => $leadId,
                'hangup_cause' => $hangupCause,
                'who'          => $who,
            ], $clientId);

            Log::info("CampaignDialer: customer hangup — agent {$agentExt} stays in conf, lead {$leadId}");

            // Do NOT mark extension idle, do NOT dispatch next lead
            // Agent controls the flow via disposition → nextCustomer
            return;
        }

        // ── Agent hung up (End Session): full teardown ──────────────────────
        ExtensionLive::markIdle($agentExt, $dbConnection);

        // Write CDR record
        $this->writeCdr($agentExt, $leadId, $campaignId, $live, $dbConnection);

        // Mark queue entry completed
        CampaignLeadQueue::on($dbConnection)
            ->where('campaign_id', $campaignId)
            ->where('lead_id', $leadId)
            ->where('status', CampaignLeadQueue::STATUS_CALLING)
            ->update([
                'status'       => CampaignLeadQueue::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

        // Update agent status back to available
        $userId = $this->extensionToUserId($agentExt, $dbConnection);
        AgentStatus::setStatus($userId, AgentStatus::AVAILABLE, $campaignId, $clientId, $dbConnection);

        // Push hangup event to agent's browser
        $this->pushToAgent($agentExt, 'call.ended', [
            'lead_id'      => $leadId,
            'hangup_cause' => $hangupCause,
            'who'          => $who,
        ], $clientId);

        Log::info("CampaignDialer: agent hangup (end session) — agent {$agentExt}, lead {$leadId}, cause {$hangupCause}");
    }

    /**
     * Customer originate failed (no answer, busy, rejected).
     * Kick agent from conference, notify browser, clean up.
     */
    public function handleCustomerOriginateFailed(array $event, string $dbConnection, int $clientId): void
    {
        $actionId = $event['ActionID'] ?? '';

        // Parse conf_room from ActionID: "cust_{confRoom}_{ts}"
        if (!preg_match('/^cust_(.+)_\d+$/', $actionId, $m)) {
            return;
        }

        $confRoom = $m[1];

        // Find the extension_live row by conf_room
        $live = ExtensionLive::on($dbConnection)
            ->where('conf_room', $confRoom)
            ->where('status', 1)
            ->first();

        if (!$live) {
            Log::warning("CampaignDialer: customer originate failed but no live extension for room {$confRoom}");
            return;
        }

        $agentExt   = (int) $live->extension;
        $leadId     = (int) $live->lead_id;
        $campaignId = (int) $live->campaign_id;

        // Persistent conference: do NOT kick agent from conf.
        // Clear customer info so agent can try next lead.
        ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->update([
                'lead_id'          => null,
                'customer_channel' => null,
                'call_status'      => ExtensionLive::CALL_STATUS_CONNECTED,
            ]);

        // Mark lead as failed in queue
        if ($leadId) {
            CampaignLeadQueue::on($dbConnection)
                ->where('campaign_id', $campaignId)
                ->where('lead_id', $leadId)
                ->where('status', CampaignLeadQueue::STATUS_CALLING)
                ->update([
                    'status'       => CampaignLeadQueue::STATUS_FAILED,
                    'completed_at' => now(),
                ]);
        }

        // Map AMI reason code to human-readable message
        $reason = $this->mapOriginateFailReason($event);

        // Push customer_noanswer event to agent's browser with reason
        $this->pushToAgent($agentExt, 'call.customer_noanswer', [
            'lead_id'     => $leadId,
            'campaign_id' => $campaignId,
            'conf_room'   => $confRoom,
            'reason'      => $reason,
        ], $clientId);

        Log::info("CampaignDialer: customer originate failed — agent {$agentExt} stays in conf, lead {$leadId}, reason: {$reason}");
    }

    /**
     * Agent did not answer within the timeout (OriginateResponse Reason != 4).
     */
    public function handleAgentNoAnswer(array $event, string $dbConnection, int $clientId): void
    {
        $actionId = $event['ActionID'] ?? '';

        // Parse IDs from ActionID: "camp_{campaignId}_lead_{leadId}_ext_{ext}_{ts}"
        if (!preg_match('/camp_(\d+)_lead_(\d+)_ext_(\d+)/', $actionId, $m)) {
            return;
        }

        [, $campaignId, $leadId, $agentExt] = $m;
        $campaignId = (int) $campaignId;
        $leadId     = (int) $leadId;
        $agentExt   = (int) $agentExt;

        ExtensionLive::markIdle($agentExt, $dbConnection);

        // Retry or fail the lead
        $entry = CampaignLeadQueue::on($dbConnection)
            ->where('campaign_id', $campaignId)
            ->where('lead_id', $leadId)
            ->first();

        if ($entry) {
            $maxRetries = self::DEFAULT_MAX_RETRIES;

            if ($entry->attempts >= $maxRetries) {
                $entry->update(['status' => CampaignLeadQueue::STATUS_FAILED]);
                Log::warning("CampaignDialer: lead {$leadId} failed (max retries)");
            } else {
                $entry->update([
                    'status'          => CampaignLeadQueue::STATUS_PENDING,
                    'next_attempt_at' => now()->addSeconds(self::DEFAULT_RETRY_DELAY),
                ]);
            }
        }

        // Update agent status back to available
        $userId = $this->extensionToUserId($agentExt, $dbConnection);
        AgentStatus::setStatus($userId, AgentStatus::AVAILABLE, null, $clientId, $dbConnection);

        // Map AMI reason code to human-readable message
        $reason = $this->mapOriginateFailReason($event);

        // Push call.failed event to agent's browser so UI shows error in real time
        $this->pushToAgent($agentExt, 'call.failed', [
            'lead_id'     => $leadId,
            'campaign_id' => $campaignId,
            'reason'      => $reason,
            'message'     => $reason,
        ], $clientId);

        Log::info("CampaignDialer: agent no-answer — ext {$agentExt}, lead {$leadId}, reason: {$reason}");

        // Try next lead immediately
        dispatch(
            (new \App\Jobs\DialNextLeadJob($campaignId, $clientId, $dbConnection))
                ->onQueue('campaign-dialer')
                ->delay(now()->addSeconds(3))
        );
    }

    // -------------------------------------------------------------------------
    // Persistent Conference: hang up customer only (disposition mode)
    // -------------------------------------------------------------------------

    /**
     * Hang up only the customer channel — no next lead dial.
     * Agent stays in conference room for disposition.
     */
    public function hangupCustomerOnly(
        int    $campaignId,
        int    $agentExt,
        int    $clientId,
        string $dbConnection
    ): array {
        // status: 1=active, 3=paused — both are "in a call"
        $live = ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->whereIn('status', [1, 3])
            ->first();

        if (!$live) {
            return ['status' => 'error', 'message' => 'Agent not in an active call'];
        }

        $previousLeadId = (int) $live->lead_id;
        $confRoom       = $live->conf_room;

        error_log("hangupCustomerOnly: agentExt={$agentExt} customer_channel=" . ($live->customer_channel ?: 'NULL')
            . " conf_room=" . ($confRoom ?: 'NULL') . " lead_id={$previousLeadId} status={$live->status}");

        // Hang up customer channel only
        try {
            if (!$this->ami->isConnected()) {
                error_log("hangupCustomerOnly: AMI not connected, connecting for client {$clientId}...");
                if (!$this->ami->connectForClient($clientId)) {
                    throw new \RuntimeException("AMI connection failed for client {$clientId}");
                }
                error_log("hangupCustomerOnly: AMI connected OK");
            }

            if ($live->customer_channel) {
                // Direct hangup — we know the exact channel name
                error_log("hangupCustomerOnly: PATH=direct_hangup customer_channel={$live->customer_channel}");
                $this->ami->hangup($live->customer_channel);
            } else {
                // Use ConfBridge kick — try stored conf_room, then agent ext as fallback
                $room = $confRoom ?: (string) $agentExt;
                error_log("hangupCustomerOnly: PATH=confbridge_kick room={$room} agentExt={$agentExt}");
                $this->ami->confbridgeKickNonAgent($room, $agentExt);
            }
            error_log("hangupCustomerOnly: AMI hangup command sent successfully");
        } catch (\Throwable $e) {
            error_log("hangupCustomerOnly: EXCEPTION — " . $e->getMessage());
            \Log::error("CampaignDialer: failed to hangup customer for ext {$agentExt}: {$e->getMessage()}");
            return [
                'status'          => 'error',
                'message'         => 'AMI hangup failed — call may still be active: ' . $e->getMessage(),
                'previous_lead_id' => $previousLeadId,
            ];
        }

        // Write CDR for completed call
        $this->writeCdr($agentExt, $previousLeadId, $campaignId, $live, $dbConnection);

        // Mark lead as completed in queue
        CampaignLeadQueue::on($dbConnection)
            ->where('campaign_id', $campaignId)
            ->where('lead_id', $previousLeadId)
            ->where('status', CampaignLeadQueue::STATUS_CALLING)
            ->update([
                'status'       => CampaignLeadQueue::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

        // Clear customer info but keep agent in conference
        ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->update([
                'lead_id'          => null,
                'customer_channel' => null,
            ]);

        return ['status' => 'ok', 'previous_lead_id' => $previousLeadId];
    }

    // -------------------------------------------------------------------------
    // Persistent Conference: hang up customer only, dial next lead
    // -------------------------------------------------------------------------

    /**
     * Hang up only the customer channel while keeping the agent in the conference.
     * Then pick the next lead and originate the customer call into the same conf room.
     *
     * Returns: ['status' => 'next_lead'|'no_more_leads', 'lead' => [...] | null]
     */
    public function hangupCustomerAndDialNext(
        int    $campaignId,
        int    $agentExt,
        int    $clientId,
        string $dbConnection
    ): array {
        // status: 1=active, 3=paused — both are "in a call"
        $live = ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->whereIn('status', [1, 3])
            ->first();

        if (!$live) {
            return ['status' => 'error', 'message' => 'Agent not in an active call'];
        }

        $previousLeadId = (int) $live->lead_id;
        $confRoom       = $live->conf_room;

        // Steps 1-3 only needed if a previous lead is still active
        // (may already have been handled by hangupCustomerOnly)
        if ($previousLeadId > 0) {
            // 1) Hang up the customer channel only
            try {
                if (!$this->ami->isConnected()) {
                    if (!$this->ami->connectForClient($clientId)) {
                        throw new \RuntimeException("AMI connection failed for client {$clientId}");
                    }
                }

                if ($live->customer_channel) {
                    error_log("hangupCustomerAndDialNext: PATH=direct_hangup customer_channel={$live->customer_channel}");
                    $this->ami->hangup($live->customer_channel);
                } else {
                    $room = $confRoom ?: (string) $agentExt;
                    error_log("hangupCustomerAndDialNext: PATH=confbridge_kick room={$room} agentExt={$agentExt}");
                    $this->ami->confbridgeKickNonAgent($room, $agentExt);
                }
            } catch (\Throwable $e) {
                Log::error("CampaignDialer: failed to hangup customer for ext {$agentExt}: {$e->getMessage()}");
                return [
                    'status'  => 'error',
                    'message' => 'AMI hangup failed — call may still be active: ' . $e->getMessage(),
                ];
            }

            // 2) Write CDR for completed call
            $this->writeCdr($agentExt, $previousLeadId, $campaignId, $live, $dbConnection);

            // 3) Mark previous lead as completed in queue
            CampaignLeadQueue::on($dbConnection)
                ->where('campaign_id', $campaignId)
                ->where('lead_id', $previousLeadId)
                ->where('status', CampaignLeadQueue::STATUS_CALLING)
                ->update([
                    'status'       => CampaignLeadQueue::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
        }

        // 4) Pick next lead from queue
        $entry = DB::connection($dbConnection)->transaction(function () use ($campaignId, $dbConnection) {
            return CampaignLeadQueue::nextDialable($campaignId, $dbConnection);
        });

        if (!$entry) {
            // No more leads — agent should end session
            $campaign = Campaign::on($dbConnection)->find($campaignId);
            if ($campaign) {
                $this->checkCampaignCompletion($campaignId, $campaign, $dbConnection);
            }

            // Clear customer channel but keep agent in conf
            ExtensionLive::on($dbConnection)
                ->where('extension', $agentExt)
                ->update(['lead_id' => null, 'customer_channel' => null, 'call_status' => ExtensionLive::CALL_STATUS_CONNECTED]);

            return ['status' => 'no_more_leads', 'lead' => null];
        }

        // 5) Mark new lead as calling
        $entry->update([
            'status'    => CampaignLeadQueue::STATUS_CALLING,
            'attempts'  => DB::raw('attempts + 1'),
            'called_at' => now(),
        ]);

        // 6) Resolve phone number for new lead
        $leadRow = DB::connection($dbConnection)
            ->table('list_data')
            ->where('id', $entry->lead_id)
            ->first();

        $phone = null;
        if ($leadRow) {
            $dialCol = DB::connection($dbConnection)
                ->table('list_header')
                ->where('list_id', $leadRow->list_id)
                ->where('is_dialing', 1)
                ->value('column_name');
            $phone = $dialCol ? ($leadRow->$dialCol ?? null) : null;
        }

        if (!$phone) {
            $entry->update(['status' => CampaignLeadQueue::STATUS_SKIPPED]);
            // Recurse to try next lead (limited by queue size)
            return $this->hangupCustomerAndDialNext($campaignId, $agentExt, $clientId, $dbConnection);
        }

        // 7) Update extension_live with new lead
        ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->update([
                'lead_id'          => $entry->lead_id,
                'call_status'      => ExtensionLive::CALL_STATUS_CONNECTED,
                'customer_channel' => null,
            ]);

        // 8) Originate customer call into same conf room
        $resolvedCli = $this->callerIdResolver->resolve(
            $dbConnection,
            $campaignId,
            $phone
        ) ?? '0000000000';

        // Resolve the outbound SIP trunk and dial prefix
        $trunkConfig = $this->resolveTrunkConfig($campaignId, $clientId, $dbConnection);
        $trunk       = $trunkConfig['trunk'];
        $dialPrefix  = $trunkConfig['prefix'];

        if (!$this->ami->isConnected()) {
            $this->ami->connectForClient($clientId);
        }

        // Build outbound channel with correct trunk and prefix
        $outboundChannel = $this->buildOutboundChannel($phone, $trunk, $dialPrefix);
        $cleanPhone      = $this->sanitizePhone($phone);
        $custActionId    = "cust_{$confRoom}_" . time();

        $this->ami->originate([
            'Channel'  => $outboundChannel,
            'Context'  => 'campaign-conf-customer-join',
            'Exten'    => 's',
            'Priority' => '1',
            'CallerID' => $resolvedCli,
            'Timeout'  => 60000,
            'ActionID' => $custActionId,
            'Variable' => [
                "LEAD_ID={$entry->lead_id}",
                "CAMPAIGN_ID={$campaignId}",
                "CLIENT_ID={$clientId}",
                "DB_CONN={$dbConnection}",
                "AGENT_EXT={$agentExt}",
                "CUSTOMER_NUM={$cleanPhone}",
                "CONF_ROOM={$confRoom}",
            ],
        ]);

        Log::info("CampaignDialer: next-customer — agent {$agentExt} stays in conf, dialing lead {$entry->lead_id} ({$phone}) via {$outboundChannel}");

        // 9) Build lead response for frontend
        $leadData = [
            'lead_id'      => $entry->lead_id,
            'phone_number' => $phone,
            'list_id'      => $leadRow->list_id ?? 0,
        ];

        // Map list_header fields to the response
        if ($leadRow) {
            $headers = DB::connection($dbConnection)
                ->table('list_header')
                ->where('list_id', $leadRow->list_id)
                ->where('is_deleted', 0)
                ->get(['column_name', 'header', 'is_dialing']);

            $fields = [];
            foreach ($headers as $h) {
                $val = $leadRow->{$h->column_name} ?? '';
                $fields[] = ['label' => $h->header, 'value' => $val, 'is_dialing' => $h->is_dialing];
            }
            $leadData['fields'] = $fields;
            $leadData['success'] = true;
        }

        return ['status' => 'next_lead', 'lead' => $leadData];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the first idle (available) agent extension for a campaign.
     * "Idle" = assigned to campaign via campaign_agents + AgentStatus is AVAILABLE
     *          + extension_live.status = 0 (not already on a call).
     */
    protected function getIdleAgentExtension(int $campaignId, string $dbConnection): ?int
    {
        // Agents explicitly assigned to this campaign via campaign_agents pivot table
        $assignedUserIds = CampaignAgent::on($dbConnection)
            ->where('campaign_id', $campaignId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (empty($assignedUserIds)) {
            Log::info("CampaignDialer: no agents assigned to campaign {$campaignId}");
            return null;
        }

        // Find those that are "available" in agent_statuses
        $availableUserIds = DB::connection($dbConnection)
            ->table('agent_statuses')
            ->whereIn('user_id', $assignedUserIds)
            ->where('status', AgentStatus::AVAILABLE)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (empty($availableUserIds)) {
            return null;
        }

        // Get their extensions from master DB users table, exclude those already on a call.
        // Always use alt_extension (WebPhone) when set; only fall back to extension
        // for users who have no alt_extension configured.
        $busyExtensions = DB::connection($dbConnection)
            ->table('extension_live')
            ->where('status', 1)
            ->pluck('extension')
            ->toArray();

        $users = DB::connection('master')
            ->table('users')
            ->whereIn('id', $availableUserIds)
            ->get(['id', 'extension', 'alt_extension']);

        foreach ($users as $user) {
            // Always prefer alt_extension (WebPhone/WebRTC).
            // Fall back to hardware extension only when alt_extension is not set.
            $ext = !empty($user->alt_extension) ? $user->alt_extension : $user->extension;
            if (empty($ext)) {
                Log::info("CampaignDialer: user {$user->id} has no extension — skipping");
                continue;
            }
            if (in_array($ext, $busyExtensions)) continue;
            return (int) $ext;
        }

        Log::info("CampaignDialer: no available agent extensions for campaign {$campaignId} (busy or unconfigured)");
        return null;
    }

    /**
     * Resolve user_id from extension number (master users table).
     * Checks both alt_extension (WebRTC) and extension (hardware).
     */
    protected function extensionToUserId(int $extension, string $dbConnection): int
    {
        // Extract client ID from connection name (mysql_123 → 123)
        $clientId = (int) str_replace('mysql_', '', $dbConnection);

        $user = DB::connection('master')
            ->table('users')
            ->where('parent_id', $clientId)
            ->where(function ($q) use ($extension) {
                $q->where('alt_extension', $extension)
                  ->orWhere('extension', $extension);
            })
            ->value('id');

        return (int) $user;
    }

    /**
     * Write a CDR record for the completed call.
     */
    protected function writeCdr(int $agentExt, int $leadId, int $campaignId, ?object $live, string $dbConnection): void
    {
        // Resolve phone from list_data via the is_dialing column
        $leadRow = DB::connection($dbConnection)->table('list_data')->where('id', $leadId)->first();
        $phone   = null;
        if ($leadRow) {
            $dialCol = DB::connection($dbConnection)
                ->table('list_header')
                ->where('list_id', $leadRow->list_id)
                ->where('is_dialing', 1)
                ->value('column_name');
            $phone = $dialCol ? ($leadRow->$dialCol ?? null) : null;
        }
        $lead = $phone;

        // Calculate duration from extension_live call_started_at
        $duration  = null;
        $startTime = now();
        $endTime   = now();
        if ($live && !empty($live->call_started_at)) {
            try {
                $startTime = Carbon::parse($live->call_started_at);
                $duration  = (int) $startTime->diffInSeconds($endTime);
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        // Derive recording filename matching Asterisk MixMonitor pattern:
        // {AGENT_EXT}-{CUSTOMER_NUM}-{LEAD_ID}-{YYYYMMDDHHMMSS}.wav
        $recording = null;
        if ($startTime && $lead) {
            $ts = $startTime instanceof Carbon ? $startTime->format('YmdHis') : Carbon::parse($startTime)->format('YmdHis');
            $cleanNum = preg_replace('/\D/', '', $lead);
            $recording = "{$agentExt}-{$cleanNum}-{$leadId}-{$ts}.wav";
        }

        DB::connection($dbConnection)->table('cdr')->insert([
            'extension'      => $agentExt,
            'route'          => 'OUT',
            'type'           => 'dialer',
            'number'         => preg_replace('/\D/', '', $lead ?? '0'),
            'channel'        => $live->channel ?? '',
            'duration'       => $duration,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'call_recording' => $recording,
            'campaign_id'    => $campaignId,
            'lead_id'        => $leadId,
        ]);
    }

    /**
     * Check if campaign is fully complete (no pending/calling entries left).
     */
    protected function checkCampaignCompletion(int $campaignId, Campaign $campaign, string $dbConnection): void
    {
        $remaining = CampaignLeadQueue::on($dbConnection)
            ->where('campaign_id', $campaignId)
            ->whereIn('status', [CampaignLeadQueue::STATUS_PENDING, CampaignLeadQueue::STATUS_CALLING])
            ->count();

        if ($remaining === 0) {
            Campaign::on($dbConnection)
                ->where('id', $campaignId)
                ->update(['dialer_status' => 'completed']);

            Log::info("CampaignDialer: campaign {$campaignId} completed — no more leads");
        }
    }

    // -------------------------------------------------------------------------
    // VoIP Provider / Trunk resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the outbound SIP trunk configuration for a campaign.
     *
     * Resolution order:
     *  1. campaign.voip_configuration_id  → voip_configuration table (trunk name + dial prefix)
     *  2. Client's sip_gateways entry     → sip_trunk_name + derive prefix from provider
     *  3. asterisk_server.trunk           → fallback with no prefix
     *
     * @return array{trunk: string, prefix: string}
     */
    protected function resolveTrunkConfig(int $campaignId, int $clientId, string $dbConnection): array
    {
        // 1) Campaign-specific VoIP configuration
        $vcId = DB::connection($dbConnection)
            ->table('campaign')
            ->where('id', $campaignId)
            ->value('voip_configuration_id');

        if ($vcId && $vcId > 0) {
            $vc = DB::connection('master')
                ->table('voip_configuration')
                ->where('id', $vcId)
                ->first();

            if ($vc && !empty($vc->name)) {
                Log::debug("CampaignDialer: trunk resolved from voip_configuration id={$vcId} — trunk={$vc->name} prefix=" . ($vc->prefix ?? ''));
                return [
                    'trunk'  => $vc->name,
                    'prefix' => $vc->prefix ?? '',
                ];
            }
        }

        // 2) Client's SIP gateway
        $gateway = DB::connection('master')
            ->table('sip_gateways')
            ->where('parent_id', $clientId)
            ->first();

        if ($gateway && !empty($gateway->sip_trunk_name)) {
            $prefix = $this->defaultPrefixForProvider($gateway->sip_trunk_provider);
            Log::debug("CampaignDialer: trunk resolved from sip_gateways — trunk={$gateway->sip_trunk_name} provider={$gateway->sip_trunk_provider} prefix={$prefix}");
            return [
                'trunk'  => $gateway->sip_trunk_name,
                'prefix' => $prefix,
            ];
        }

        // 3) Fallback to asterisk_server.trunk (AMI connection default)
        $trunk = $this->ami->getTrunk() ?: 'outbound-trunk';
        Log::debug("CampaignDialer: trunk fallback to asterisk_server — trunk={$trunk}");
        return [
            'trunk'  => $trunk,
            'prefix' => '',
        ];
    }

    /**
     * Default dial prefix for well-known VoIP providers.
     * For US dialing, Plivo and Twilio require E.164 format (+1XXXXXXXXXX).
     */
    protected function defaultPrefixForProvider(?string $provider): string
    {
        return match (strtolower(trim($provider ?? ''))) {
            'plivo'  => '+1',
            'twilio' => '+1',
            default  => '',
        };
    }

    /**
     * Build the outbound PJSIP channel string.
     *
     * Sanitises the phone number (digits only), applies the dial prefix,
     * and formats as "PJSIP/{trunk}/{prefix}{phone}".
     *
     * @param  string  $phone   Raw phone number (may contain non-digit chars)
     * @param  string  $trunk   PJSIP endpoint name (e.g. "pilivo", "Airespring1")
     * @param  string  $prefix  Dial prefix (e.g. "+1", "#13517131", "")
     */
    protected function buildOutboundChannel(string $phone, string $trunk, string $prefix = ''): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // Avoid double country-code: if prefix is +1 and phone already starts with 1 + 10 digits, strip the leading 1
        if ($prefix === '+1' && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        $channel = "PJSIP/{$trunk}/{$prefix}{$digits}";
        error_log("buildOutboundChannel: raw_phone={$phone} trunk={$trunk} prefix={$prefix} → {$channel}");
        return $channel;
    }

    /**
     * Sanitise a phone number for AMI Variable values (digits only).
     */
    protected function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    /**
     * Map AMI OriginateResponse reason code to a human-readable failure message.
     * Also checks Response field and common provider error indicators.
     */
    protected function mapOriginateFailReason(array $event): string
    {
        $response = $event['Response'] ?? '';
        $reason   = (int) ($event['Reason'] ?? -1);

        // Check for provider-specific error text in the event
        // AMI may include a 'Cause-txt' or 'ChannelStateDesc' with provider details
        $causeTxt = $event['Cause-txt'] ?? $event['cause-txt'] ?? '';
        if (!empty($causeTxt)) {
            return "Provider error: {$causeTxt}";
        }

        // Map standard AMI Originate reason codes
        // See: https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Events/OriginateResponse/
        return match ($reason) {
            0       => 'No answer — call timed out',
            1       => 'Line busy',
            3       => 'No route to destination',
            5       => 'Call rejected by provider',
            8       => 'Congestion — trunk or provider unavailable',
            default => !empty($response) && strtolower($response) === 'failure'
                        ? 'Call failed — provider could not connect'
                        : 'Call failed',
        };
    }

    /**
     * Push a Pusher event to a private channel keyed on agent extension.
     * Channel: "private-agent-dialer.{extension}"
     */
    protected function pushToAgent(int $agentExt, string $event, array $data, int $clientId): void
    {
        try {
            $pusher = new Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                ['cluster' => config('broadcasting.connections.pusher.options.cluster'), 'useTLS' => true]
            );

            // Public channel: "dialer-agent.{extension}" — matches existing app pattern
            // (no Pusher auth endpoint needed)
            $pusher->trigger("dialer-agent.{$agentExt}", $event, $data);
        } catch (\Throwable $e) {
            Log::warning("CampaignDialer: Pusher push failed — {$e->getMessage()}");
        }
    }
}
