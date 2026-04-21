<?php

namespace App\Http\Controllers;

use App\Model\Client\Campaign;
use App\Model\Client\CampaignLeadQueue;
use App\Model\Client\ExtensionLive;
use App\Model\Client\AgentStatus;
use App\Services\AsteriskAmiService;
use App\Services\CallerIdResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AmiOriginateController extends Controller
{
    /**
     * Agent-first campaign originate via URL.
     *
     * Flow (identical to dialer-studio):
     *  1. Ring agent's webphone (PJSIP/{extension})
     *  2. Agent answers → enters ConfBridge
     *  3. AMI listener auto-dials the next lead into the same conference
     *
     * GET /api-dial/originate?extension=34562&client_id=1&campaign_id=5
     */
    public function originate(Request $request): JsonResponse
    {
        $extension  = $request->input('extension', '34562');
        $clientId   = (int) $request->input('client_id', 1);
        $campaignId = (int) $request->input('campaign_id');

        if (!$campaignId) {
            return response()->json([
                'success' => false,
                'message' => 'campaign_id is required. Example: ?extension=34562&client_id=1&campaign_id=5',
            ], 400);
        }

        $dbConnection = "mysql_{$clientId}";

        // ── Validate campaign ────────────────────────────────────────────
        $campaign = Campaign::on($dbConnection)->find($campaignId);

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => "Campaign {$campaignId} not found",
            ], 404);
        }

        // ── Auto-populate lead queue if empty ──────────────────────────
        $queueCount = CampaignLeadQueue::on($dbConnection)
            ->where('campaign_id', $campaignId)
            ->count();

        if ($queueCount === 0) {
            CampaignLeadQueue::populateFromCampaign($campaignId, $dbConnection);
        }

        // ── Pick next lead from campaign queue ───────────────────────────
        $entry = DB::connection($dbConnection)->transaction(function () use ($campaignId, $dbConnection) {
            return CampaignLeadQueue::nextDialable($campaignId, $dbConnection);
        });

        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => "No dialable leads in campaign {$campaignId}",
            ], 404);
        }

        // Mark lead as being called
        $entry->update([
            'status'    => CampaignLeadQueue::STATUS_CALLING,
            'attempts'  => DB::raw('attempts + 1'),
            'called_at' => now(),
        ]);

        // ── Load lead phone number ───────────────────────────────────────
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
            return response()->json([
                'success' => false,
                'message' => "Lead {$entry->lead_id} has no phone number — skipped",
            ], 400);
        }

        // ── Conference room + extension_live ─────────────────────────────
        $confRoom = "camp_{$campaignId}_{$entry->lead_id}_{$extension}_" . time();

        ExtensionLive::markLive(
            $extension,
            $campaignId,
            $entry->lead_id,
            null,
            ExtensionLive::CALL_STATUS_RINGING,
            $confRoom,
            $dbConnection
        );

        // ── Resolve caller ID ────────────────────────────────────────────
        $callerIdResolver = new CallerIdResolverService();
        $resolvedCli = $callerIdResolver->resolve($dbConnection, $campaignId, $phone) ?? '0000000000';

        // ── Connect to AMI and originate ─────────────────────────────────
        $ami = new AsteriskAmiService();

        if (!$ami->connectForClient($clientId)) {
            $entry->update(['status' => CampaignLeadQueue::STATUS_PENDING]);
            ExtensionLive::markIdle($extension, $dbConnection);
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to Asterisk AMI',
            ], 500);
        }

        $actionId = "camp_{$campaignId}_lead_{$entry->lead_id}_ext_{$extension}_" . time();

        try {
            $ami->originate([
                'Channel'  => "PJSIP/{$extension}",
                'Context'  => 'campaign-conf-agent',
                'Exten'    => 's',
                'Priority' => '1',
                'CallerID' => '"Campaign Call" <' . $resolvedCli . '>',
                'Timeout'  => 30000,
                'ActionID' => $actionId,
                'Variable' => [
                    "LEAD_ID={$entry->lead_id}",
                    "CAMPAIGN_ID={$campaignId}",
                    "CLIENT_ID={$clientId}",
                    "DB_CONN={$dbConnection}",
                    "AGENT_EXT={$extension}",
                    "CUSTOMER_NUM={$phone}",
                    "CALLER_ID_NUM={$resolvedCli}",
                    "TRUNK={$ami->getTrunk()}",
                    "CALL_TIMEOUT=60",
                    "CONF_ROOM={$confRoom}",
                ],
            ]);

            $ami->disconnect();

            Log::info("AmiOriginate: agent {$extension}, lead {$entry->lead_id}, campaign {$campaignId}, phone {$phone}");

            return response()->json([
                'success'     => true,
                'message'     => "Ringing agent {$extension} — on answer, lead {$entry->lead_id} ({$phone}) will be dialed automatically",
                'action_id'   => $actionId,
                'lead_id'     => $entry->lead_id,
                'phone'       => $phone,
                'campaign_id' => $campaignId,
                'conf_room'   => $confRoom,
            ]);
        } catch (\Exception $e) {
            $ami->disconnect();
            $entry->update(['status' => CampaignLeadQueue::STATUS_PENDING]);
            ExtensionLive::markIdle($extension, $dbConnection);

            return response()->json([
                'success' => false,
                'message' => 'Originate failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
