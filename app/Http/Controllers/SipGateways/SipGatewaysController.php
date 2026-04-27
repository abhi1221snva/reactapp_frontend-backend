<?php

namespace App\Http\Controllers\SipGateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

use App\Model\Master\UserExtension;
use App\Model\Master\RvmDomainList;
use App\Services\PjsipRealtimeService;
use App\Model\Client\Ringless\RinglessCampaign;



use App\Model\Master\SipGateway\SipGateways;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class SipGatewaysController extends Controller
{

    /**
     * @OA\Get(
     *     path="/sip-gateways",
     *     summary="Get list of SIP gateways for the authenticated client",
     *     tags={"SipGateways"},
     *     security={{"Bearer":{}}},
     *  *      @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of SIP gateways retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="gateways List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="gateway_name", type="string", example="Twilio SIP"),
     *                     @OA\Property(property="ip_address", type="string", example="192.168.1.1"),
     *                     @OA\Property(property="protocol", type="string", example="UDP"),
     *                     @OA\Property(property="port", type="integer", example=5060),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-24T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-24T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */

    public function sipGatwayList(Request $request)
    {
        $gateways = SipGateways::where('parent_id', $request->auth->parent_id)->get()->all();
        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($gateways);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $gateways = array_slice($gateways, $start, $limit, false);

            return $this->successResponse("Sip gateways List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $gateways
            ]);
        }
        return $this->successResponse("gateways List", $gateways);
    }

    public function sipGatwayList_old_code(Request $request)
    {
        $gateways = SipGateways::where('parent_id', $request->auth->parent_id)->get()->all();
        return $this->successResponse("gateways List", $gateways);
    }
    /**
     * @OA\Put(
     *     path="/sip-gateway",
     *     summary="Create a new SIP gateway",
     *     tags={"SipGateways"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"client_name", "sip_trunk_provider", "sip_trunk_name", "sip_trunk_host", "sip_trunk_username", "sip_trunk_password"},
     *             @OA\Property(property="client_name", type="string", example="Acme Corp"),
     *             @OA\Property(property="sip_trunk_provider", type="string", example="Twilio"),
     *             @OA\Property(property="sip_trunk_name", type="string", example="twilio_trunk_01"),
     *             @OA\Property(property="sip_trunk_host", type="string", example="sip.twilio.com"),
     *             @OA\Property(property="sip_trunk_username", type="string", example="twilio_user"),
     *             @OA\Property(property="sip_trunk_password", type="string", example="securepassword123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SIP gateway created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="sip gateway created"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="client_name", type="string", example="Acme Corp"),
     *                 @OA\Property(property="sip_trunk_provider", type="string", example="Twilio"),
     *                 @OA\Property(property="sip_trunk_name", type="string", example="twilio_trunk_01"),
     *                 @OA\Property(property="sip_trunk_host", type="string", example="sip.twilio.com"),
     *                 @OA\Property(property="sip_trunk_username", type="string", example="twilio_user"),
     *                 @OA\Property(property="rvm_domain_id", type="integer", example=5),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-24T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(property="sip_trunk_name", type="array", @OA\Items(type="string", example="The SIP trunk name already exists. Please choose a different name."))
     *             )
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        // Validation rules
        $this->validate($request, [
            'client_name' => 'required|string|max:255',
            'sip_trunk_provider' => 'required|string|max:255',
            'sip_trunk_name' => 'required|string|max:255|unique:user_extensions,name',
            'sip_trunk_host' => 'required|string|max:255',
            'sip_trunk_username' => 'required|string|max:255',
            'sip_trunk_password' => 'required|string|max:255',
        ], [
            'sip_trunk_name.unique' => 'The SIP trunk name already exists. Please choose a different name.'
        ]);

        $dt['name'] = $request->sip_trunk_name;
        $dt['username'] = $request->sip_trunk_username;
        $dt['fullname'] = $request->sip_trunk_name;
        $dt['host'] = $request->sip_trunk_host;
        $dt['secret']   = $request->sip_trunk_password;
        $dt['context'] =  'trunkinbound-' . $request->sip_trunk_provider;
        $dt['nat'] = 'force_rport,comedia';
        $dt['qualify'] = 'no';
        $dt['type'] = 'friend';

        $attributes = $request->all();

        $SipGateways = SipGateways::create($attributes);
        $SipGateways->parent_id = $request->auth->parent_id;
        $SipGateways->save();
        $addUserExtension = UserExtension::create($dt);

        // Sync SIP gateway extension to PJSIP realtime tables
        PjsipRealtimeService::syncExtension($dt['username'], $dt['secret'], $dt['context'], $dt['fullname']);

        $rvm_domain['folder_link'] = env('API_URL') . '/upload/ringless_files/'; //3_ivr_1725792548.wav

        $rvm_domain_log = RvmDomainList::create($rvm_domain);

        $SipGateways->rvm_domain_id = $rvm_domain_log->id;
        $SipGateways->save();

        return $this->successResponse("sip gateway created", $SipGateways->toArray());
    }

    /**
     * @OA\Get(
     *     path="/sip-gateways/{id}",
     *     summary="Get SIP Gateway by ID",
     *     tags={"SipGateways"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SIP Gateway",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SIP Gateway fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SIP Gateway fetched successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="client_name", type="string", example="Acme Corp"),
     *                 @OA\Property(property="sip_trunk_provider", type="string", example="Twilio"),
     *                 @OA\Property(property="sip_trunk_name", type="string", example="twilio_trunk_01"),
     *                 @OA\Property(property="sip_trunk_host", type="string", example="sip.twilio.com"),
     *                 @OA\Property(property="sip_trunk_username", type="string", example="twilio_user"),
     *                 @OA\Property(property="rvm_domain_id", type="integer", example=5),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-24T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="SIP Gateway not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SIP Gateway not found")
     *         )
     *     )
     * )
     */

    public function edit(Request $request, $id)
    {
        // Fetch the SIP Gateway record by its ID
        $gateway = SipGateways::find($id);

        // Check if the record exists
        if (!$gateway) {
            return $this->errorResponse("SIP Gateway not found", 404);
        }

        // Return the record or pass it to a view if needed
        return $this->successResponse("SIP Gateway fetched successfully", $gateway->toArray());
    }


    /**
     * @OA\Post(
     *     path="/update-sip-gateways",
     *     summary="Update SIP Gateway",
     *     tags={"SipGateways"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={
     *                 "sip_id", "client_name", "sip_trunk_provider",
     *                 "sip_trunk_name", "sip_trunk_host", "sip_trunk_username", "sip_trunk_password"
     *             },
     *             @OA\Property(property="sip_id", type="integer", example=1),
     *             @OA\Property(property="client_name", type="string", example="Acme Corp"),
     *             @OA\Property(property="sip_trunk_provider", type="string", example="Twilio"),
     *             @OA\Property(property="sip_trunk_name", type="string", example="twilio_trunk_01"),
     *             @OA\Property(property="sip_trunk_host", type="string", example="sip.twilio.com"),
     *             @OA\Property(property="sip_trunk_username", type="string", example="twilio_user"),
     *             @OA\Property(property="sip_trunk_password", type="string", example="securepassword123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SIP Gateway updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SIP Gateway updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="client_name", type="string", example="Acme Corp"),
     *                 @OA\Property(property="sip_trunk_provider", type="string", example="Twilio"),
     *                 @OA\Property(property="sip_trunk_name", type="string", example="twilio_trunk_01"),
     *                 @OA\Property(property="sip_trunk_host", type="string", example="sip.twilio.com"),
     *                 @OA\Property(property="sip_trunk_username", type="string", example="twilio_user"),
     *                 @OA\Property(property="rvm_domain_id", type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The SIP trunk name already exists. Please choose a different name.")
     *         )
     *     )
     * )
     */
    public function update(Request $request)
    {
        // Log::info('reached edit', [$request->all()]);

        // Find the SIP Gateway by its ID
        $gateway = SipGateways::find($request->sip_id);
        Log::info('reached edit', ['gateway' => $gateway]);

        // Validation rules, excluding the current sip_trunk_name from the unique check
        $this->validate($request, [
            'client_name' => 'required|string|max:255',
            'sip_trunk_provider' => 'required|string|max:255',
            'sip_trunk_name' => 'required|string|max:255|unique:sip_gateways,sip_trunk_name,' . $gateway->id, // Exclude current record
            'sip_trunk_host' => 'required|string|max:255',
            'sip_trunk_username' => 'required|string|max:255',
            'sip_trunk_password' => 'required|string|max:255',
        ], [
            'sip_trunk_name.unique' => 'The SIP trunk name already exists. Please choose a different name.',
        ]);



        // Prepare the data for updating UserExtension
        $dt['name'] = $request->sip_trunk_name;
        $dt['username'] = $request->sip_trunk_username;
        $dt['fullname'] = $request->sip_trunk_name;
        $dt['host'] = $request->sip_trunk_host;
        $dt['secret'] = $request->sip_trunk_password;
        $dt['context'] = 'trunkinbound-' . $request->sip_trunk_provider;
        $dt['nat'] = 'force_rport,comedia';
        $dt['qualify'] = 'no';
        $dt['type'] = 'friend';

        // Find the related UserExtension record
        $userExtension = UserExtension::where('fullname', $gateway->sip_trunk_name)->first();
        Log::info('reached edit', ['userExtension' => $userExtension]);

        if ($userExtension) {
            // Update the existing UserExtension record
            $userExtension->update($dt);
        }

        // Update RvmDomainList if needed
        $rvm_domain['folder_link'] = env('API_URL') . '/upload/ringless_files/';

        // Find the RvmDomainList by some criteria (e.g., ID or other identifying fields)
        $rvmDomain = RvmDomainList::where('id', $gateway->rvm_domain_id)->first();

        if ($rvmDomain) {
            // Update the existing RvmDomainList
            $rvmDomain->update($rvm_domain);
        }

        // Update the gateway with the updated RvmDomain ID (if necessary)
        // Update the SIP Gateway attributes
        $gateway->client_name = $request->client_name;
        $gateway->sip_trunk_provider = $request->sip_trunk_provider;
        $gateway->sip_trunk_name = $request->sip_trunk_name;
        $gateway->sip_trunk_host = $request->sip_trunk_host;
        $gateway->sip_trunk_username = $request->sip_trunk_username;
        $gateway->sip_trunk_password = $request->sip_trunk_password;
        $gateway->rvm_domain_id = $rvmDomain->id;
        $gateway->save();

        // Return a success response
        return $this->successResponse("SIP Gateway updated successfully", $gateway->toArray());
    }


    /**
     * @OA\Get(
     *     path="/sip-gateway-delete/{id}",
     *     summary="delete SIP Gateway by ID",
     *     tags={"SipGateways"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SIP Gateway",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SIP Gateway deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SIP Gateway fetched successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="client_name", type="string", example="Acme Corp"),
     *                 @OA\Property(property="sip_trunk_provider", type="string", example="Twilio"),
     *                 @OA\Property(property="sip_trunk_name", type="string", example="twilio_trunk_01"),
     *                 @OA\Property(property="sip_trunk_host", type="string", example="sip.twilio.com"),
     *                 @OA\Property(property="sip_trunk_username", type="string", example="twilio_user"),
     *                 @OA\Property(property="rvm_domain_id", type="integer", example=5),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-24T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="SIP Gateway not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SIP Gateway not found")
     *         )
     *     )
     * )
     */
    public function delete(Request $request, $id)
    {
        Log::info('Delete Request received for ID:', [$id]);

        // Find the SIP Gateway by ID
        $gateway = SipGateways::find($id);

        if (!$gateway) {
            // Return error response if the gateway is not found
            return response()->json(['success' => false, 'message' => "SIP Gateway not found."], 404);
        }

        // Check if any RinglessCampaign is associated with the SIP Gateway
        $campaignExists = RinglessCampaign::on("mysql_" . $request->auth->parent_id)->where('sip_gateway_id', $id)->exists();

        if ($campaignExists) {
            // If a campaign is using the SIP Gateway, return a failure message and stop deletion
            $campaignName = RinglessCampaign::on("mysql_" . $request->auth->parent_id)->where('sip_gateway_id', $id)->first()->title; // Retrieve campaign name
            return response()->json([
                'success' => false,
                'message' => "The SIP Gateway is already configured with the campaign '{$campaignName}'. Please change or remove it from the campaign before deleting."
            ], 400);
        }

        try {
            $userExtension = UserExtension::where('name', $gateway->sip_trunk_name)->first();
            if ($userExtension) {
                // Remove PJSIP realtime records before deleting user_extension
                PjsipRealtimeService::deleteExtension($userExtension->username ?? $userExtension->name);
                $userExtension->delete();
            }

            $rvmDomain = RvmDomainList::where('id', $gateway->rvm_domain_id)->first();
            if ($rvmDomain) {
                $rvmDomain->delete();
            }

            // Delete the SIP Gateway
            $gateway->delete();

            // Return success response after deletion
            return response()->json(['success' => true, 'message' => "SIP Gateway deleted successfully."]);
        } catch (\Exception $e) {
            // Handle any exceptions during deletion
            Log::error("Error during deletion: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => "Error occurred while deleting SIP Gateway: " . $e->getMessage()], 500);
        }
    }
}
       /* if($request->sip_trunk_provider == 'twilio')
        {
            $this->validate($request, [
                'sip_twilio_sid' => 'required|string|max:255',
                'sip_twilio_token'=>'required|string|max:255'
            ]);

            $checkRow = SipGateways::where('sip_trunk_name',$request->sip_trunk_name)->where('sip_twilio_sid',$request->sip_twilio_sid)->where('sip_twilio_token',$request->sip_twilio_token)->where('sip_trunk_provider',$request->sip_trunk_provider)->get()->first();

        //echo "<pre>";print_r($dt);die;

            

            if($checkRow)
            {
                $id = $checkRow->id;
                $update = SipGateways::findOrFail($id);
                $update->client_name = $request->client_name;
                $update->sip_trunk_provider = $request->sip_trunk_provider;
                $update->sip_trunk_name = $request->sip_trunk_name;
                $update->sip_host = $request->sip_host;
                $update->sip_trunk_username = $request->sip_trunk_username;
                $update->sip_trunk_password = $request->sip_trunk_password;
                $update->sip_trunk_context = $request->sip_trunk_context;
                $update->saveOrFail();

                $updateUserExtension = UserExtension::where('username',$request->sip_trunk_username)->get()->first();
                $updateUserExtension->name = $request->sip_trunk_name;
                $updateUserExtension->username = $request->sip_trunk_username;
                $updateUserExtension->fullname = $request->sip_trunk_name;
                $updateUserExtension->host = $request->sip_host;
                $updateUserExtension->secret = $request->sip_trunk_password;
                $updateUserExtension->context = $request->sip_trunk_context;
                $updateUserExtension->saveOrFail();


                return $this->successResponse("sip_twilio_sid and sip_twilio_token already exist. sip gateway data updated", $update->toArray());
            }
            else
            {
                $attributes = $request->all();
      //  echo "<pre>";print_r($attributes);

        //echo "<pre>";print_r($dt);die;


                $client = SipGateways::create($attributes);
                $addUserExtension = UserExtension::create($dt);

                return $this->successResponse("sip gateway created", $client->toArray());
            }
        }

        else
        if($request->sip_trunk_provider == 'plivo')
        {
            $this->validate($request, [
                'sip_plivo_auth_token'=>'required|string|max:255'
            ]);

            $checkRow = SipGateways::where('sip_trunk_name',$request->sip_trunk_name)->where('sip_plivo_auth_token',$request->sip_plivo_auth_token)->where('sip_trunk_provider',$request->sip_trunk_provider)->get()->first();

            //echo "<pre>";print_r($checkRow);die;

            if($checkRow)
            {
                $id = $checkRow->id;
                $update = SipGateways::findOrFail($id);
                $update->client_name = $request->client_name;
                $update->sip_trunk_provider = $request->sip_trunk_provider;
                $update->sip_trunk_name = $request->sip_trunk_name;
                $update->sip_host = $request->sip_host;
                $update->sip_trunk_username = $request->sip_trunk_username;
                $update->sip_trunk_password = $request->sip_trunk_password;
                $update->sip_trunk_context = $request->sip_trunk_context;
                $update->saveOrFail();

                $updateUserExtension = UserExtension::where('username',$request->sip_trunk_username)->get()->first();
                $updateUserExtension->name = $request->sip_trunk_name;
                $updateUserExtension->username = $request->sip_trunk_username;
                $updateUserExtension->fullname = $request->sip_trunk_name;
                $updateUserExtension->host = $request->sip_host;
                $updateUserExtension->secret = $request->sip_trunk_password;
                $updateUserExtension->context = $request->sip_trunk_context;
                $updateUserExtension->saveOrFail();


                return $this->successResponse("sip_plivo_auth_token already exist. sip gateway data updated", $update->toArray());
            }
            else
            {


                $dt['canreinvite']='no';
                $dt['insecure']='port,invite';
                $dt['qualify']='yes';
                $dt['disallow']='all';
                $dt['allow']='ulaw';
                $dt['allow']='alaw';
                $dt['dtmfmode']='rfc2833';

//            echo "<pre>";print_r($dt);die;


                $attributes = $request->all();
                $client = SipGateways::create($attributes);
                $addUserExtension = UserExtension::create($dt);

                return $this->successResponse("sip gateway created", $client->toArray());
            }
        }
        else
        {
            return $this->failResponse("Invalid sip trunk provider", $request->toArray());
        }*/
