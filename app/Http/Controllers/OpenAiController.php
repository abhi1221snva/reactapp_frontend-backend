<?php

namespace App\Http\Controllers;

use App\Model\Client\OpenAISetting;
use App\Model\Client\SmsAI;
use App\Model\Client\SmsAiReport;

use App\Model\Master\Did;
use Illuminate\Support\Facades\DB;





use Illuminate\Http\Request;

class OpenAiController extends Controller
{

    /**
     * @OA\Post(
     *     path="/receive-sms-ai",
     *     tags={"OpenAi"},
     *     summary="Store Incoming SMS from Twilio or Telnyx",
     *     description="Parses and stores incoming SMS data sent from Twilio or Telnyx webhooks.",
     *     operationId="storeSMS",
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="provider",
     *         in="query",
     *         required=true,
     *         description="SMS provider name (twilio or telnyx)",
     *         @OA\Schema(type="string", example="twilio")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         description="SMS payload from Twilio or Telnyx",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Schema(
     *                     title="Twilio Format",
     *                     @OA\Property(property="SmsSid", type="string", example="SMXXXXXXXXXXXXXXXXXXXX"),
     *                     @OA\Property(property="Body", type="string", example="Hello from Twilio"),
     *                     @OA\Property(property="From", type="string", example="+1234567890"),
     *                     @OA\Property(property="To", type="string", example="+0987654321"),
     *                     @OA\Property(property="SmsStatus", type="string", example="received"),
     *                     @OA\Property(property="MesssageType", type="string", example="text") 
     *                 ),
     *                 @OA\Schema(
     *                     title="Telnyx Format",
     *                     @OA\Property(property="data", type="object",
     *                         @OA\Property(property="payload", type="object",
     *                             @OA\Property(property="id", type="string", example="unique_telnyx_id"),
     *                             @OA\Property(property="text", type="string", example="Hello from Telnyx"),
     *                             @OA\Property(property="direction", type="string", example="inbound"),
     *                             @OA\Property(property="received_at", type="string", format="date-time", example="2025-06-17T10:45:00Z"),
     *                             @OA\Property(property="sent_at", type="string", format="date-time", example="2025-06-17T10:44:59Z"),
     *                             @OA\Property(property="completed_at", type="string", format="date-time", example="2025-06-17T10:45:01Z"),
     *                             @OA\Property(property="from", type="object",
     *                                 @OA\Property(property="phone_number", type="string", example="+19876543210")
     *                             ),
     *                             @OA\Property(property="to", type="array",
     *                                 @OA\Items(type="object",
     *                                     @OA\Property(property="phone_number", type="string", example="+10987654321"),
     *                                     @OA\Property(property="status", type="string", example="delivered")
     *                                 )
     *                             )
     *                         )
     *                     )
     *                 )
     *             }
     *         )
     *     ),
     
     *     @OA\Response(
     *         response=404,
     *         description="DID not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="DID not found"),
     *             @OA\Property(property="data")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid Telnyx Payload",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid Telnyx Payload"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */

    public function store(Request $request)
    {
        try {
            $response = json_decode(file_get_contents('php://input'));
            if (isset($_REQUEST['provider']) == 'twilio') {
                $array_twilio = array();



                if (isset($response->MesssageType)) {
                    $array_twilio['number'] = $response->To;
                    $array_twilio['did'] = $response->From;
                    $array_twilio['sms_type'] = 'outgoing';
                    $array_twilio['type'] = 'ai';
                } else {
                    $array_twilio['number'] = $response->From;
                    $array_twilio['did'] = $response->To;
                    $array_twilio['sms_type'] = 'incoming';
                    $array_twilio['type'] = 'merchant';
                }

                $date = date('Y-m-d');
                $time = date('H:i:s');
                $array_twilio['operator'] = 'twilio';
                $array_twilio['date'] = $date . 'T' . $time;
                $array_twilio['message'] = $response->Body;
                $array_twilio['message_status'] = $response->SmsStatus;
                $array_twilio['sms_id'] = $response->SmsSid;

                $did_check = str_replace('+', '', $array_twilio['did']);
                $did = Did::where('cli', $did_check)->get()->first();
                $parent_id = $did->parent_id;
                $sms_id =  $response->SmsSid;

                $array_twilio['json_data'] = json_encode($response);

                //echo "<pre>";print_r($array_twilio);die;

                $sms_ai = new SmsAI($array_twilio);
                $sms_ai->setConnection("mysql_" . $parent_id);
                $sms_ai->save();

                return $this->successResponse("SMS AI Data Added Successfully", [$sms_ai]);
            }


            $array_telnyx = array();
            $array_telnyx['message'] = $response->data->payload->text;
            $array_telnyx['operator'] = 'telnyx';

            $array_telnyx['sms_type'] = $response->data->payload->direction;
            if ($array_telnyx['sms_type'] == 'outbound') {
                $completed_at = $response->data->payload->completed_at;
                if (empty($completed_at)) {
                    $array_telnyx['date'] = $response->data->payload->sent_at;
                } else {

                    $array_telnyx['date'] = $response->data->payload->completed_at;
                }

                $array_telnyx['number'] = $response->data->payload->to[0]->phone_number;
                $array_telnyx['did'] = $response->data->payload->from->phone_number;
                $array_telnyx['type'] = 'ai';
                $array_telnyx['sms_type'] = 'outgoing';
                $array_telnyx['message_status'] = $response->data->payload->to[0]->status;
                $array_telnyx['sms_id'] = $response->data->payload->id;
            } else
                if ($array_telnyx['sms_type'] == 'inbound') {
                $array_telnyx['did'] = $response->data->payload->to[0]->phone_number;
                $array_telnyx['number'] = $response->data->payload->from->phone_number;
                $array_telnyx['type'] = 'merchant';
                $array_telnyx['sms_type'] = 'incoming';
                $array_telnyx['date'] = $response->data->payload->received_at;
                $array_telnyx['sms_id'] = $response->data->payload->id;
                $array_telnyx['message_status'] = $response->data->payload->to[0]->status;
            }


            //echo $array_telnyx['date'] = $response->data->payload->completed_at;die;

            $did_check = str_replace('+', '', $array_telnyx['did']);

            //$did = Did::where('cli',$did_check)->where('voip_provider','telnyx_sms')->get()->first();
            $did = Did::where('cli', $did_check)->get()->first();

            $parent_id = $did->parent_id;

            $sms_id = $response->data->payload->id;

            $checked = SmsAI::on('mysql_' . $parent_id)->where('sms_id', $sms_id)->where('sms_type', 'outgoing')->get()->first();
            if (!empty($checked)) {
                if ($checked->sms_type == 'outgoing') {
                    if (!empty($response->data->payload->completed_at)) {
                        /* $sql = "UPDATE sms_ai_lead_report set delivery_status = :delivery_status WHERE merchant_number = :merchant_number";
                            DB::connection('mysql_'.$parent_id)->update($sql, array('merchant_number' => $checked->number,'delivery_status'=>'1'));*/

                        $checked->message_status = $response->data->payload->to[0]->status;
                        $checked->date = $response->data->payload->completed_at;
                        $checked->save();
                        return $this->successResponse("SMS AI Data Added Successfully", [$checked]);
                    }
                }
            } else {
                $array_telnyx['json_data'] = json_encode($response);

                //echo "<pre>";print_r($array_telnyx);die;


                $sms_ai = new SmsAI($array_telnyx);
                $sms_ai->setConnection("mysql_" . $parent_id);
                $sms_ai->save();
                return $this->successResponse("SMS AI Data Added Successfully", [$sms_ai]);
            }
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of SMS AI Setting", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Get(
     *     path="/open-ai-setting",
     *     summary="Get SMS AI Setting for the authenticated client",
     *     description="Returns the SMS AI settings associated with the authenticated client based on their parent_id.",
     *     tags={"OpenAi"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Setting",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SMS AI Setting"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="openai_key", type="string", example="sk-..."),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-04-24T10:30:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-25T10:30:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to list of SMS AI Setting"
     *     )
     * )
     */

    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $setting = [];
            $setting = OpenAISetting::on("mysql_$clientId")->get()->first();

            return $this->successResponse("SMS AI Setting", [$setting]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of SMS AI Setting", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Get(
     *     path="/open-ai-setting-website",
     *     summary="Get OpenAI Website Settings",
     *     description="Retrieves OpenAI-related settings for the specified client (hardcoded to client ID 5 in this case).",
     *     tags={"OpenAi"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="OpenAI setting retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function listWebsite(Request $request)
    {
        try {
            //$clientId = $request->auth->parent_id;
            $clientId = 5;
            $setting = [];
            $setting = OpenAISetting::on("mysql_$clientId")->get()->first();

            return $this->successResponse("SMS AI Setting", [$setting]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of SMS AI Setting", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }



    /**
     * @OA\Post(
     *     path="/add-open-ai-setting",
     *     summary="Create SMS AI Settings and DID",
     *     description="Creates new SMS AI settings and associates a new DID for a given parent account.",
     *     operationId="createSmsAiSetting",
     *     tags={"OpenAi"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"cli", "access_token", "webhook_url", "sms_ai_api_url"},
     *             @OA\Property(property="introduction", type="string", example="Introduction to SMS AI", description="Introduction text for the SMS AI"),
     *             @OA\Property(property="description", type="string", example="Detailed description of the SMS AI settings", description="Description of the SMS AI settings"),
     *             @OA\Property(property="cli", type="string", example="+1234567890", description="CLI (DID) for the SMS AI"),
     *             @OA\Property(property="access_token", type="string", example="your-access-token", description="Access token for API authentication"),
     *             @OA\Property(property="webhook_url", type="string", example="https://your-webhook-url.com", description="Webhook URL for SMS AI integration"),
     *             @OA\Property(property="sms_ai_api_url", type="string", example="https://your-sms-ai-api.com", description="URL of the SMS AI API endpoint")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Settings and DID created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="introduction", type="string", example="Introduction to SMS AI"),
     *                 @OA\Property(property="description", type="string", example="Detailed description of the SMS AI settings"),
     *                 @OA\Property(property="cli", type="string", example="+1234567890"),
     *                 @OA\Property(property="access_token", type="string", example="your-access-token"),
     *                 @OA\Property(property="webhook_url", type="string", example="https://your-webhook-url.com"),
     *                 @OA\Property(property="sms_ai_api_url", type="string", example="https://your-sms-ai-api.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error - required fields missing"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save SMS AI setting"
     *     )
     * )
     */


    public function create(Request $request)
    {


        try {
            $input = $request->all();
            $smtp = new OpenAISetting();
            $smtp->setConnection("mysql_" . $request->auth->parent_id);
            //$smtp->mail_type = 'online application';

            if (!empty($input["introduction"])) $smtp->introduction = $input["introduction"];
            if (!empty($input["description"])) $smtp->description = $input["description"];
            if (!empty($input["cli"])) $smtp->cli = $input["cli"];
            if (!empty($input["access_token"])) $smtp->access_token = $input["access_token"];
            if (!empty($input["webhook_url"])) $smtp->webhook_url = $input["webhook_url"];
            if (!empty($input["sms_ai_api_url"])) $smtp->sms_ai_api_url = $input["sms_ai_api_url"];

            $smtp->saveOrFail();

            $did = new Did();
            $did->parent_id = $request->auth->parent_id;
            $did->cli = '1' . $smtp->cli;
            $did->voip_provider = 'telnyx_sms';
            $did->save();

            return $this->successResponse("Added Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save SMS AI setting", [$exception->getMessage()], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/update-open-ai-setting/{id}",
     *     operationId="updateOpenAiSetting",
     *     tags={"OpenAi"},
     *     summary="Update SMS AI system setting",
     *     description="Updates an existing SMS AI system setting by ID.",
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the system setting",
     *         required=true,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="introduction", type="string", example="Intro text here"),
     *             @OA\Property(property="description", type="string", example="Detailed description"),
     *             @OA\Property(property="access_token", type="string", example="sk-XXXX"),
     *             @OA\Property(property="cli", type="string", example="9876543210"),
     *             @OA\Property(property="webhook_url", type="string", format="url", example="https://yourapp.com/webhook"),
     *             @OA\Property(property="sms_ai_api_url", type="string", format="url", example="https://sms-ai.api/send")
     *         )
     *     ),
     *     @OA\Response(
     *     response=200,
     *     description="SMS AI Setting updated",
     *     @OA\JsonContent(
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="message", type="string", example="SMS AI Setting Updated"),
     *         @OA\Property(property="data")
     *     )
     * ),
     *     @OA\Response(
     *         response=404,
     *         description="Setting not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="SMS AI System Setting Not Found"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update setting",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update SMS AI System Setting"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */

    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;


        $did = Did::where('cli', '1' . $request->input("cli"))->where('voip_provider', 'telnyx_sms')->get()->first();
        if (empty($did)) {

            $did = new Did();
            $did->parent_id = $request->auth->parent_id;
            $did->cli = '1' . $request->input("cli");
            $did->voip_provider = 'telnyx_sms';
            $did->save();
        }
        /* else
            {
                return 2;
            }*/

        try {
            $System = OpenAISetting::on("mysql_$clientId")->findOrFail($id);
            if ($request->has("introduction")) {
                $System->introduction = $request->input("introduction");
            }
            if ($request->has("description")) {
                $System->description = $request->input("description");
            }
            if ($request->has("access_token")) {
                $System->access_token = $request->input("access_token");
            }
            if ($request->has("cli")) {
                $System->cli = $request->input("cli");
            }

            if ($request->has("webhook_url")) {
                $System->webhook_url = $request->input("webhook_url");
            }
            if ($request->has("sms_ai_api_url")) {
                $System->sms_ai_api_url = $request->input("sms_ai_api_url");
            }

            $System->saveOrFail();




            return $this->successResponse("SMS AI Seting Updated", $System->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("SMS AI System Setting  Not Found", [
                "Invalid SMS AI System Setting  id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update SMS AI System Setting ", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    /**
     * @OA\Post(
     *     path="/sms-ai-history",
     *     summary="Retrieve SMS AI history by DID and phone number",
     *     description="Returns the SMS message history associated with a specific DID (cli) and phone number.",
     *     operationId="getSmsAiHistory",
     *     tags={"OpenAi"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"cli", "number"},
     *             @OA\Property(property="cli", type="string", example="+1234567890", description="DID (CLI) number"),
     *             @OA\Property(property="number", type="string", example="+1987654321", description="Customer phone number")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Data",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SMS AI Data"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="did", type="string", example="+1234567890"),
     *                     @OA\Property(property="number", type="string", example="+1987654321"),
     *                     @OA\Property(property="message", type="string", example="Hello from AI!"),
     *                     @OA\Property(property="date", type="string", format="date-time", example="2025-04-25T15:00:00"),
     *                     @OA\Property(property="operator", type="string", example="twilio"),
     *                     @OA\Property(property="type", type="string", example="ai"),
     *                     @OA\Property(property="status", type="string", example="delivered"),
     *                     @OA\Property(property="sms_type", type="string", example="outgoing")
     *                 )
     *             ),
     *             @OA\Property(property="count", type="integer", example=3)
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error - required fields missing"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to View SMS AI Data"
     *     )
     * )
     */
    public function smsHistory(Request $request)
    {
        $this->validate($request, [
            'cli' => 'required',
            'number' => 'required',
        ]);

        try {
            $cli = $request->cli;
            $number = $request->number;
            $sms_ai = SmsAI::on("mysql_" . $request->auth->parent_id)->where('did', $cli)->Where('number', $number)->select(
                'did',
                'number',
                'message',
                'date',
                'operator',
                'type',
                'status',
                'sms_type'
            )->get()->all();
            $sms_ai['count'] = count($sms_ai);
            return $this->successResponse("SMS AI Data", $sms_ai);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View SMS AI Data", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/delete-message-ai",
     *     summary="Delete SMS AI records by cli and number",
     *     description="Deletes SMS AI data.",
     *     operationId="deleteSmsAiData",
     *     tags={"OpenAi"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"cli", "number"},
     *             @OA\Property(property="cli", type="string", example="+1234567890", description="DID number"),
     *             @OA\Property(property="number", type="string", example="+1987654321", description="Customer phone number")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Delete info",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Delete info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No data Found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to delete data info"
     *     )
     * )
     */
    public function delete(Request $request)
    {
        try {
            $sms_ai_data = SmsAI::on("mysql_" . $request->auth->parent_id)->where('did', $request->cli)->where('number', $request->number);
            $sms_ai_data->delete();
            return $this->successResponse("Delete info", [$sms_ai_data]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No data Found");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to delete data info", [], $exception);
        }
    }

    /*
    public function smsAiWalletBalance($parent_id,$voip_provider)
    {
        $TELNYX_SMS_AI_TOKEN = env('TELNYX_SMS_AI_TOKEN');
        $TELNYX_SMS_AI_URL   = env('TELNYX_SMS_AI_URL');
        $cli_add_update_url = $TELNYX_SMS_AI_URL.'sms/user-cli';
        
        $TELNYX_SMS_AI_WEBHOOK   = env('TELNYX_SMS_AI_WEBHOOK');
        $TWILIO_SMS_AI_WEBHOOK   = env('TELNYX_SMS_AI_WEBHOOK').'?provider=twilio';

        $sms_setting = SmsProviders::on('mysql_'.$parent_id)->where("status",'1')->where('provider',$voip_provider)->get()->first();
        
        $sms_ai_wallet_balance = SmsAiWallet::on('mysql_'.$parent_id)->get()->first();
        $intCharge = '0.0005';
        $currencyCode =  $sms_ai_wallet_balance->currency_code;
        $balance =  $sms_ai_wallet_balance->amount;



        if($voip_provider == 'telnyx')
        {
            $telnyx_api_key = $sms_setting->api_key;
            $array = ['cli'=>'+'.$request->cli,'webhook'=>$TELNYX_SMS_AI_WEBHOOK,'telnyx_key' => $telnyx_api_key,'telnyx_public_key' => 'string','service_status' => "False"];
        }
        else
        if($voip_provider == 'twilio')
        {

            $twilio_api_key = $sms_setting->api_key;
            $twilio_auth_id = $sms_setting->auth_id;
            $array = ['cli'=>'+'.$request->cli,'webhook'=>$TWILIO_SMS_AI_WEBHOOK,'twilio_account_sid' => $twilio_auth_id,'twilio_auth_token' => $twilio_api_key, 'service_status' => "False"];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $addCli);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER,['accept:application/json','x-api-key: '.$TELNYX_SMS_AI_TOKEN,'Content-Type: application/json',]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($array));
        $response = curl_exec($ch);
        curl_close($ch);

        return $sms_ai_wallet_balance->amount;

    }

    */

    /**
     * @OA\Post(
     *     path="/sms-ai-report-email",
     *     tags={"OpenAi"},
     *     summary="Store SMS AI Email Report",
     *     description="Stores SMS AI report data with time range and dynamic fields",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="time_period",
     *                 type="object",
     *                 @OA\Property(property="from", type="string", format="date", example="2025-06-01"),
     *                 @OA\Property(property="to", type="string", format="date", example="2025-06-17")
     *             ),
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="SMS AI Data Added Successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="SMS AI Data Added Successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="client_id", type="integer", example=9),
     *                @OA\Property(property="report_data", type="string", example={"key":"value"}),
     *                 @OA\Property(property="time_period_from", type="string", format="date", example="2025-06-01"),
     *                 @OA\Property(property="time_period_to", type="string", format="date", example="2025-06-17"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-17T15:00:00"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-17T15:00:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save data",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Failed to save data"),
     *             @OA\Property(property="message", type="string", example="Database connection not found or query error")
     *         )
     *     )
     * )
     */

    public function smsEmailReport(Request $request)
    {
        try {
            $clientId = 9; //$request->client_id;
            $smsAiReport = new SmsAiReport();

            // Ensure the database connection exists before using it
            $connectionName = "mysql_" . $clientId;
            if (!array_key_exists($connectionName, config('database.connections'))) {
                return response()->json(['error' => 'Invalid database connection'], 500);
            }
            $smsAiReport->setConnection($connectionName);

            $data = $request->all(); // Get the whole JSON

            // Add client_id inside JSON data
            $data['client_id'] = $clientId;

            // Extract time_period if it exists
            $timePeriod = $data['time_period'] ?? null;
            $from = $timePeriod['from'] ?? null;
            $to = $timePeriod['to'] ?? null;

            // Ensure report_data is JSON encoded
            $smsAiReport->report_data = json_encode($data); // FIX: Convert to JSON string
            $smsAiReport->time_period_from = $from;
            $smsAiReport->time_period_to = $to;

            $smsAiReport->saveOrFail();

            return response()->json([
                'message' => 'SMS AI Data Added Successfully',
                'data' => $smsAiReport
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to save data',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
