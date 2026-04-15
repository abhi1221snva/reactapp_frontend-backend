<?php

namespace App\Services;

use App\Model\Client\Campaign;
use App\Model\Client\CampaignLeadQueue;
use App\Model\Client\ExtensionLive;
use App\Model\Client\AgentStatus;
use App\Model\Client\CampaignAgent;
use App\Services\CallerIdResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

/**
 * Orchestrates the agent-first click-to-call campaign dialer.
 *
 * Flow:
 *  1. dialNextLead()  — pick next queued lead, find idle agent, AMI originate → agent
 *  2. handleAgentAnswered()  — agent picked up; update extension_live; Asterisk now dials customer
 *  3. handleCallBridged()    — customer answered, agent+customer bridged; push lead data to UI
 *  4. handleCallHangup()     — call ended; reset extension, log CDR, dispatch next dial
 *  5. handleAgentNoAnswer()  — agent timeout; retry or move to failed
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
        $entry = DB::connection($dbConnection)->transaction(function () use ($campaignId) {
            return CampaignLeadQueue::nextDialable($campaignId);
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
            return $this->dialNextLead($campaignId, $clientId, $dbConnection);
        }

        // Pre-populate extension_live so frontend can see "ringing" immediately
        ExtensionLive::on($dbConnection)->markLive(
            $agentExt,
            $campaignId,
            $entry->lead_id,
            null,
            ExtensionLive::CALL_STATUS_RINGING
        );

        // Update agent status
        AgentStatus::on($dbConnection)->setStatus(
            $this->extensionToUserId($agentExt, $dbConnection),
            AgentStatus::ON_CALL,
            $campaignId,
            $clientId
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

        // Ensure AMI is connected for this client
        if (!$this->ami->isConnected()) {
            $this->ami->connectForClient($clientId);
        }

        // Originate: ring the AGENT first. When agent answers, dialplan dials customer.
        $this->ami->originate([
            'Channel'  => "PJSIP/{$agentExt}",
            'Context'  => 'campaign-outbound',
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
                "CUSTOMER_NUM={$lead->phone_number}",
                // CALLER_ID_NUM is set in the dialplan before Dial() so the customer
                // sees the resolved CLI on their phone screen (not the agent's extension)
                "CALLER_ID_NUM={$resolvedCli}",
                "TRUNK={$this->ami->getTrunk()}",
                "CALL_TIMEOUT=60",
            ],
        ]);

        Log::info("CampaignDialer: originated to agent {$agentExt} for lead {$entry->lead_id} (campaign {$campaignId})");

        return $entry;
    }

    // -------------------------------------------------------------------------
    // AMI event handlers (called by AmiListenCommand)
    // -------------------------------------------------------------------------

    /**
     * Agent answered the incoming SIP call. Dialplan now dials the customer.
     */
    public function handleAgentAnswered(array $event, string $dbConnection): void
    {
        $leadId    = (int) ($event['LeadID'] ?? 0);
        $agentExt  = (int) ($event['AgentExt'] ?? 0);
        $channel   = $event['Channel'] ?? null;

        if (!$leadId || !$agentExt) {
            return;
        }

        ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->where('lead_id', $leadId)
            ->update([
                'status'      => 1,
                'channel'     => $channel,
                'call_status' => ExtensionLive::CALL_STATUS_CONNECTED,
            ]);

        Log::info("CampaignDialer: agent {$agentExt} answered, lead {$leadId} — dialing customer");
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
            ->update(['call_status' => ExtensionLive::CALL_STATUS_BRIDGED]);

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
     */
    public function handleCallHangup(array $event, string $dbConnection, int $clientId): void
    {
        $leadId     = (int) ($event['LeadID'] ?? 0);
        $agentExt   = (int) ($event['AgentExt'] ?? 0);
        $campaignId = (int) ($event['CampaignID'] ?? 0);
        $hangupCause = (int) ($event['HangupCause'] ?? 0);

        if (!$leadId || !$agentExt) {
            return;
        }

        // Read extension_live before clearing it (needed for CDR)
        $live = ExtensionLive::on($dbConnection)
            ->where('extension', $agentExt)
            ->first();

        // Reset extension to idle
        ExtensionLive::on($dbConnection)->markIdle($agentExt);

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
        AgentStatus::on($dbConnection)->setStatus($userId, AgentStatus::AVAILABLE, $campaignId, $clientId);

        // Push hangup event to agent's browser
        $this->pushToAgent($agentExt, 'call.ended', [
            'lead_id'    => $leadId,
            'hangup_cause' => $hangupCause,
        ], $clientId);

        Log::info("CampaignDialer: hangup — agent {$agentExt}, lead {$leadId}, cause {$hangupCause}");

        // Dispatch next call after a short grace period
        dispatch(
            (new \App\Jobs\DialNextLeadJob($campaignId, $clientId, $dbConnection))
                ->onQueue('campaign-dialer')
                ->delay(now()->addSeconds(2))
        );
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

        ExtensionLive::on($dbConnection)->markIdle($agentExt);

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
        AgentStatus::on($dbConnection)->setStatus($userId, AgentStatus::AVAILABLE, null, $clientId);

        // Try next lead immediately
        dispatch(
            (new \App\Jobs\DialNextLeadJob($campaignId, $clientId, $dbConnection))
                ->onQueue('campaign-dialer')
                ->delay(now()->addSeconds(3))
        );
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

        // Get their extensions from master DB users table, exclude those already on a call
        $busyExtensions = DB::connection($dbConnection)
            ->table('extension_live')
            ->where('status', 1)
            ->pluck('extension')
            ->toArray();

        $user = DB::connection('master')
            ->table('users')
            ->whereIn('id', $availableUserIds)
            ->whereNotNull('extension')
            ->when(!empty($busyExtensions), fn ($q) => $q->whereNotIn('extension', $busyExtensions))
            ->first();

        return $user ? (int) $user->extension : null;
    }

    /**
     * Resolve user_id from extension number (master users table).
     */
    protected function extensionToUserId(int $extension, string $dbConnection): int
    {
        $user = DB::connection('master')
            ->table('users')
            ->where('extension', $extension)
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

        DB::connection($dbConnection)->table('cdr')->insert([
            'extension'   => $agentExt,
            'route'       => 'OUT',
            'type'        => 'dialer',
            'number'      => preg_replace('/\D/', '', $lead ?? '0'),
            'channel'     => $live->channel ?? '',
            'duration'    => null,
            'start_time'  => $live ? now() : now(),
            'campaign_id' => $campaignId,
            'lead_id'     => $leadId,
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

    /**
     * Push a Pusher event to a private channel keyed on agent extension.
     * Channel: "private-agent-dialer.{extension}"
     */
    protected function pushToAgent(int $agentExt, string $event, array $data, int $clientId): void
    {
        try {
            $pusher = new Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                ['cluster' => env('PUSHER_APP_CLUSTER'), 'useTLS' => true]
            );

            // Public channel: "dialer-agent.{extension}" — matches existing app pattern
            // (no Pusher auth endpoint needed)
            $pusher->trigger("dialer-agent.{$agentExt}", $event, $data);
        } catch (\Throwable $e) {
            Log::warning("CampaignDialer: Pusher push failed — {$e->getMessage()}");
        }
    }
}
