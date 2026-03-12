<?php

namespace App\Http\Controllers;

use App\Model\Client\TwilioTrunk;
use App\Services\TwilioService;
use App\Jobs\SyncTwilioTrunksJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Get(
 *   path="/twilio/trunks",
 *   summary="List Twilio SIP trunks",
 *   operationId="twilioListTrunks",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Trunk list")
 * )
 *
 * @OA\Post(
 *   path="/twilio/trunks",
 *   summary="Create a Twilio SIP trunk",
 *   operationId="twilioCreateTrunk",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"friendly_name"},
 *     @OA\Property(property="friendly_name", type="string"),
 *     @OA\Property(property="origination_url", type="string")
 *   )),
 *   @OA\Response(response=200, description="Trunk created"),
 *   @OA\Response(response=500, description="Twilio error")
 * )
 *
 * @OA\Delete(
 *   path="/twilio/trunks/{sid}",
 *   summary="Delete a Twilio SIP trunk",
 *   operationId="twilioDeleteTrunk",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="sid", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Trunk deleted")
 * )
 *
 * @OA\Put(
 *   path="/twilio/trunks/{sid}/origination-url",
 *   summary="Update a trunk's origination URL",
 *   operationId="twilioUpdateTrunkOriginationUrl",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="sid", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"origination_url"},
 *     @OA\Property(property="origination_url", type="string")
 *   )),
 *   @OA\Response(response=200, description="URL updated")
 * )
 *
 * @OA\Post(
 *   path="/twilio/trunks/sync",
 *   summary="Sync Twilio trunks from Twilio API",
 *   operationId="twilioSyncTrunks",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Sync dispatched")
 * )
 */
class TwilioTrunkController extends Controller
{
    // -- List SIP trunks ----------------------------------------------------
    //
    // WEBHOOK-DRIVEN: The sync-on-read block that called the Twilio API on
    // every list request has been removed. Trunks are now kept current via
    // webhooks and the manual sync() method below.

    public function list(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = 'mysql_' . $clientId;

        $trunks = TwilioTrunk::on($conn)->where('status', 'active')->get();

        return $this->successResponse('OK', ['trunks' => $trunks]);
    }

    // -- Create SIP trunk ---------------------------------------------------

    public function create(Request $request)
    {
        $clientId     = $request->auth->parent_id ?: $request->auth->id;
        $conn         = 'mysql_' . $clientId;
        $friendlyName = $request->input('friendly_name');

        if (!$friendlyName) {
            return $this->failResponse('friendly_name is required.', [], null, 422);
        }

        try {
            $service = TwilioService::forClient($clientId);
            $data    = $service->createSipTrunk($friendlyName);

            $trunk = TwilioTrunk::on($conn)->create([
                'sid'           => $data['sid'],
                'friendly_name' => $data['friendly_name'],
                'domain_name'   => $data['domain_name'] ?? null,
                'status'        => 'active',
            ]);

            return $this->successResponse('SIP trunk created.', ['trunk' => $trunk]);

        } catch (\Exception $e) {
            Log::error('Twilio create trunk', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to create SIP trunk.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Delete SIP trunk ---------------------------------------------------

    public function delete(Request $request, string $sid)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = 'mysql_' . $clientId;

        $trunk = TwilioTrunk::on($conn)->where('sid', $sid)->first();
        if (!$trunk) {
            return $this->failResponse('Trunk not found.', [], null, 404);
        }

        try {
            $service = TwilioService::forClient($clientId);
            $service->deleteSipTrunk($sid);

            $trunk->status = 'deleted';
            $trunk->save();

            return $this->successResponse('SIP trunk deleted.');

        } catch (\Exception $e) {
            Log::error('Twilio delete trunk', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to delete SIP trunk.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Update origination URL ---------------------------------------------

    public function updateOriginationUrl(Request $request, string $sid)
    {
        $clientId       = $request->auth->parent_id ?: $request->auth->id;
        $conn           = 'mysql_' . $clientId;
        $originationUrl = $request->input('origination_url');

        if (!$originationUrl) {
            return $this->failResponse('origination_url is required.', [], null, 422);
        }

        $trunk = TwilioTrunk::on($conn)->where('sid', $sid)->first();
        if (!$trunk) {
            return $this->failResponse('Trunk not found.', [], null, 404);
        }

        try {
            $service = TwilioService::forClient($clientId);
            $result  = $service->updateTrunkOriginationUrl($sid, $originationUrl);

            $trunk->origination_url = $originationUrl;
            $trunk->save();

            return $this->successResponse('Origination URL updated.', ['trunk' => $trunk, 'twilio' => $result]);

        } catch (\Exception $e) {
            Log::error('Twilio update trunk URL', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to update origination URL.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Manual sync (on-demand) -------------------------------------------
    //
    // Admins can POST /twilio/trunks/sync to queue a full trunk re-sync from
    // the Twilio API. This replaces the old automatic sync-on-read behaviour.

    public function sync(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;

        SyncTwilioTrunksJob::dispatch($clientId)
            ->onQueue('twilio');

        Log::info('Twilio manual trunk sync queued', ['client' => $clientId]);

        return $this->successResponse('Sync job queued.');
    }
}
