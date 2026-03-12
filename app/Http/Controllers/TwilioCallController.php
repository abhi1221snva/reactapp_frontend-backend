<?php

namespace App\Http\Controllers;

use App\Model\Client\TwilioCall;
use App\Model\Client\TwilioRecording;
use App\Services\TwilioService;
use App\Jobs\SyncTwilioCallsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Post(
 *   path="/twilio/calls/make",
 *   summary="Initiate an outbound Twilio call",
 *   operationId="twilioMakeCall",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"to","from"},
 *     @OA\Property(property="to", type="string", example="+15551234567"),
 *     @OA\Property(property="from", type="string", example="+15559876543"),
 *     @OA\Property(property="lead_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Call initiated"),
 *   @OA\Response(response=422, description="Validation error"),
 *   @OA\Response(response=500, description="Twilio error")
 * )
 *
 * @OA\Get(
 *   path="/twilio/calls",
 *   summary="List Twilio call logs",
 *   operationId="twilioListCalls",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="date_till", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
 *   @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=25)),
 *   @OA\Response(response=200, description="Call list")
 * )
 *
 * @OA\Get(
 *   path="/twilio/calls/{sid}",
 *   summary="Get a single Twilio call by SID",
 *   operationId="twilioGetCall",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="sid", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Call details"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *   path="/twilio/calls/sync",
 *   summary="Sync Twilio call records from Twilio API",
 *   operationId="twilioSyncCalls",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Sync dispatched")
 * )
 *
 * @OA\Get(
 *   path="/twilio/recordings",
 *   summary="List Twilio call recordings",
 *   operationId="twilioListRecordings",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="call_sid", in="query", @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Recording list")
 * )
 */
class TwilioCallController extends Controller
{
    // -- Make outbound call -------------------------------------------------

    public function makeCall(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $agentId    = $request->auth->id;
        $conn       = 'mysql_' . $clientId;
        $to         = $request->input('to');
        $from       = $request->input('from');
        $campaignId = $request->input('campaign_id');

        if (!$to) {
            return $this->failResponse('to number is required.', [], null, 422);
        }

        // Auto-pick number from pool if no from supplied
        if (!$from && $campaignId) {
            $from = TwilioService::nextNumberForCampaign((int) $campaignId, $clientId);
        }

        if (!$from) {
            return $this->failResponse('No caller ID available. Provide from or assign numbers to this campaign.', [], null, 422);
        }

        try {
            $service  = TwilioService::forClient($clientId);
            $twimlUrl = env('APP_URL') . '/twilio/webhook/call-status';
            $callData = $service->makeCall($to, $from, $twimlUrl, [
                'campaign_id' => $campaignId,
                'agent_id'    => $agentId,
            ]);

            // Persist initial record to DB immediately so the call is
            // visible in the log right away; the webhook will update it.
            TwilioCall::on($conn)->create([
                'call_sid'    => $callData['call_sid'],
                'from_number' => $from,
                'to_number'   => $to,
                'direction'   => 'outbound',
                'status'      => $callData['status'],
                'campaign_id' => $campaignId,
                'agent_id'    => $agentId,
                'started_at'  => \Carbon\Carbon::now(),
            ]);

            return $this->successResponse('Call initiated.', ['call' => $callData]);

        } catch (\Exception $e) {
            Log::error('Twilio make call', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to initiate call.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Call log -----------------------------------------------------------
    //
    // WEBHOOK-DRIVEN: The sync-on-read block (Twilio API call before the DB
    // query) has been removed. Call records are now kept up-to-date by
    // TwilioIngestCallJob which is dispatched from the /twilio/webhook/call-status
    // endpoint. This method now reads directly from the local twilio_calls table.
    // Use the sync() method below if an on-demand refresh is needed.

    public function list(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = 'mysql_' . $clientId;

        $perPage    = (int) $request->input('limit', 25);
        $page       = max(1, (int) $request->input('page', 1));
        $campaignId = $request->input('campaign_id');
        $agentId    = $request->input('agent_id');
        $status     = $request->input('status');
        $direction  = $request->input('direction');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');

        $query = TwilioCall::on($conn)->orderByDesc('id');

        if ($campaignId) $query->where('campaign_id', $campaignId);
        if ($agentId)    $query->where('agent_id',    $agentId);
        if ($status)     $query->where('status',      $status);
        if ($direction)  $query->where('direction',   $direction);
        if ($dateFrom)   $query->whereDate('started_at', '>=', $dateFrom);
        if ($dateTo)     $query->whereDate('started_at', '<=', $dateTo);

        $total = $query->count();
        $calls = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->successResponse('OK', [
            'calls'        => $calls,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
        ]);
    }

    public function getById(Request $request, string $sid)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = 'mysql_' . $clientId;

        $call = TwilioCall::on($conn)->where('call_sid', $sid)->first();
        if (!$call) {
            return $this->failResponse('Call not found.', [], null, 404);
        }

        return $this->successResponse('OK', ['call' => $call]);
    }

    // -- Manual sync (on-demand) -------------------------------------------
    //
    // Admins can POST /twilio/calls/sync to queue a full re-sync from the
    // Twilio API. This replaces the old automatic sync-on-read behaviour.

    public function sync(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;

        SyncTwilioCallsJob::dispatch($clientId)
            ->onQueue('twilio');

        Log::info('Twilio manual call sync queued', ['client' => $clientId]);

        return $this->successResponse('Sync job queued.');
    }

    // -- Recordings --------------------------------------------------------

    public function getRecordings(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = 'mysql_' . $clientId;
        $callSid  = $request->input('call_sid');

        $query = TwilioRecording::on($conn)->orderByDesc('id');
        if ($callSid) $query->where('call_sid', $callSid);

        $perPage    = (int) $request->input('limit', 25);
        $page       = max(1, (int) $request->input('page', 1));
        $total      = $query->count();
        $recordings = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->successResponse('OK', [
            'recordings'   => $recordings,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
        ]);
    }
}
