<?php

namespace App\Http\Controllers;

use App\Model\Client\PlivoCall;
use App\Model\Client\PlivoRecording;
use App\Services\PlivoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Post(
 *   path="/plivo/calls/make",
 *   summary="Initiate an outbound Plivo call",
 *   operationId="plivoMakeCall",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"to","from"},
 *     @OA\Property(property="to", type="string", example="+15551234567"),
 *     @OA\Property(property="from", type="string", example="+15559876543"),
 *     @OA\Property(property="lead_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Call initiated"),
 *   @OA\Response(response=422, description="Validation error"),
 *   @OA\Response(response=500, description="Plivo error")
 * )
 *
 * @OA\Post(
 *   path="/plivo/calls/{uuid}/hangup",
 *   summary="Hang up an active Plivo call",
 *   operationId="plivoHangupCall",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="uuid", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Call hung up"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Get(
 *   path="/plivo/calls",
 *   summary="List Plivo call logs",
 *   operationId="plivoListCalls",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="date_till", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
 *   @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=25)),
 *   @OA\Response(response=200, description="Call list")
 * )
 *
 * @OA\Get(
 *   path="/plivo/calls/{uuid}",
 *   summary="Get a single Plivo call by UUID",
 *   operationId="plivoGetCall",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="uuid", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Call details"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Get(
 *   path="/plivo/recordings",
 *   summary="List Plivo call recordings",
 *   operationId="plivoListRecordings",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="call_uuid", in="query", @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Recording list")
 * )
 */
class PlivoCallController extends Controller
{
    // -- Make outbound call -------------------------------------------------------

    public function makeCall(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $agentId    = $request->auth->id;
        $conn       = "mysql_{$clientId}";
        $to         = $request->input('to');
        $from       = $request->input('from');
        $campaignId = $request->input('campaign_id');

        if (!$to) {
            return $this->failResponse('to number is required.', [], null, 422);
        }

        // Auto-pick number from pool when no 'from' supplied
        if (!$from && $campaignId) {
            $from = PlivoService::nextNumberForCampaign((int) $campaignId, $clientId);
        }

        if (!$from) {
            return $this->failResponse(
                'No caller ID available. Provide "from" or assign numbers to this campaign.',
                [], null, 422
            );
        }

        try {
            $service  = PlivoService::forClient($clientId);
            $callData = $service->makeCall($to, $from, [
                'campaign_id' => $campaignId,
                'agent_id'    => $agentId,
            ]);

            PlivoCall::on($conn)->create([
                'call_uuid'   => $callData['call_uuid'],
                'from_number' => $from,
                'to_number'   => $to,
                'direction'   => 'outbound',
                'call_status' => $callData['status'],
                'campaign_id' => $campaignId,
                'agent_id'    => $agentId,
                'started_at'  => \Carbon\Carbon::now(),
            ]);

            return $this->successResponse('Call initiated.', ['call' => $callData]);

        } catch (\Exception $e) {
            Log::error('Plivo make call', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to initiate call.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Hangup an active call ----------------------------------------------------

    public function hangup(Request $request, string $uuid)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        try {
            $service = PlivoService::forClient($clientId);
            $service->hangupCall($uuid);

            PlivoCall::on($conn)->where('call_uuid', $uuid)->update([
                'call_status' => 'canceled',
                'ended_at'    => \Carbon\Carbon::now(),
            ]);

            return $this->successResponse('Call terminated.');

        } catch (\Exception $e) {
            Log::error('Plivo hangup call', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to hang up call.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Call log -----------------------------------------------------------------

    public function list(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $conn       = "mysql_{$clientId}";

        $perPage    = (int) $request->input('limit', 25);
        $page       = max(1, (int) $request->input('page', 1));
        $campaignId = $request->input('campaign_id');
        $agentId    = $request->input('agent_id');
        $status     = $request->input('status');
        $direction  = $request->input('direction');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');

        $query = PlivoCall::on($conn)->orderByDesc('id');

        if ($campaignId) $query->where('campaign_id', $campaignId);
        if ($agentId)    $query->where('agent_id',    $agentId);
        if ($status)     $query->where('call_status', $status);
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

    // -- Single call by UUID ------------------------------------------------------

    public function getById(Request $request, string $uuid)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $call = PlivoCall::on($conn)->where('call_uuid', $uuid)->first();

        if (!$call) {
            // Fallback: fetch live from Plivo API
            try {
                $service  = PlivoService::forClient($clientId);
                $liveCall = $service->getCall($uuid);
                return $this->successResponse('OK', ['call' => $liveCall, 'source' => 'api']);
            } catch (\Exception $e) {
                return $this->failResponse('Call not found.', [], null, 404);
            }
        }

        return $this->successResponse('OK', ['call' => $call]);
    }

    // -- Recordings ---------------------------------------------------------------

    public function getRecordings(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";
        $callUuid = $request->input('call_uuid');

        $query = PlivoRecording::on($conn)->orderByDesc('id');
        if ($callUuid) $query->where('call_uuid', $callUuid);

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