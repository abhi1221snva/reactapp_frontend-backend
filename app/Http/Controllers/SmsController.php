<?php

namespace App\Http\Controllers;

use App\Model\Client\wallet;
use App\Model\Master\Did;
use App\Model\Sms;
use App\Model\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\SmtpSetting;
use App\Mail\GenericMail;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use Plivo\RestClient;
use Pusher\Pusher;



class SmsController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    protected $pusher;

    public function __construct(Request $request, sms $sms)
    {
        $this->request = $request;
        $this->model = $sms;

        // Initialize Pusher for real-time SMS notifications
        $this->pusher = new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true
            ]
        );
    }


    /**
     * @OA\Get(
     *     path="/sms",
     *     tags={"SMS"},
     *     summary="Get list of SMS",
     *     description="Fetches a list of SMS records from the database",
     *     operationId="getSms",
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="SMS list retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS list"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="from", type="string", example="+123456789"),
     *                     @OA\Property(property="to", type="string", example="+987654321"),
     *                     @OA\Property(property="body", type="string", example="Test message"),
     *                     @OA\Property(property="received_at", type="string", format="date-time", example="2025-06-19T15:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch SMS",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function getSms()
    {
        try {
            $response = $this->model->smsDetails($this->request);
            return $this->successResponse("SMS list", $response);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /*
     * Update Exclude Number detail
     * @return json
     */

    /**
     * @OA\Post(
     *      path="/sms-by-did",
     *      summary="Show sms list using did number",
     *      operationId="getSmsByDid",
     *      tags={"SMS"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="token",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="number",
     *                     type="number"
     *                 ),
     *                  @OA\Property(
     *                     property="did",
     *                     type="number"
     *                 ),
     *                 example={"id": "xxxxxx", "did":"xxxxxx" , "number": "xxxxxx"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Sms list"
     *      )
     * )
     */
    public function getSmsByDid()
    {
        $this->validate($this->request, [
            'number'    => 'required',
            'did'       => 'required'
        ]);
        try {
            $response = $this->model->smsDetailsByDid($this->request);
            return response()->json($response);
        } catch (\Throwable $exception) {
            return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Post(
     *      path="/sms-by-did-recent",
     *      summary="Show recent sms  using did number",
     *      operationId="getSmsByDidRecent",
     *      tags={"SMS"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                  @OA\Property(
     *                     property="last_id",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="token",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="number",
     *                     type="number"
     *                 ),
     *                  @OA\Property(
     *                     property="did",
     *                     type="number"
     *                 ),
     *                 example={"token": "token" , "number": "xxxxxx","did": "xxxxxx","last_id": "xxxxxx"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Sms list"
     *      )
     * )
     */
    public function getSmsByDidRecent()
    {
        $response = $this->model->smsDetailsByDidRecent($this->request);
        return response()->json($response);
    }

    /*
     * Update Exclude Number detail
     * @return json
     */

    public function editSms()
    {
        $this->validate($this->request, [
            'id' => 'required|numeric'
        ]);
        $response = $this->model->editSms($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *      path="/send-sms",
     *      summary="Send sms",
     *      operationId="sendFax",
     *      tags={"SMS"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="to",
     *                     type="number"
     *                 ),
     *                 @OA\Property(
     *                     property="from",
     *                     type="number"
     *                 ),
     *                 @OA\Property(
     *                     property="message",
     *                     type="string"
     *                 ),
     *                 example={"to": "xxxxxx", "from": "xxxxxx", "message": " "}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Sms sent"
     *      )
     * )
     */
    public function sendSms()
    {
        $this->validate($this->request, [
            'to' => 'required|numeric',
            'from' => 'required|numeric',
            'date' => 'required',

        ]);
        $response = $this->model->sendSms($this->request);
        return response()->json($response);
    }

    public function getSmsCountDetails()
    {
        $response = $this->model->getSmsCountDetails($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *      path="/unread-sms-count",
     *      summary="unread sms count",
     *      operationId="getUnreadSms",
     *      tags={"SMS"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="id",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="token",
     *                     type="string"
     *                 ),
     *                 example={"id": "xxxxxx", "token": "xxxxxx"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Sms count"
     *      )
     * )
     */
    public function getUnreadSms()
    {
        $response = $this->model->getUnreadSms($this->request);
        return response()->json($response);
    }


    public function getUnreadSmsOpenAI()
    {
        $response = $this->model->getUnreadSmsOpenAI($this->request);
        return response()->json($response);
    }

  public function smsDidList()
{
    try {
        $response = $this->model->smsDidList($this->request);

        // Convert to array of objects
        $response = collect($response)->map(function ($item) {
            return [
                'cli' => $item['cli'],
                'voip_provider' => $item['voip_provider'],
            ];
        })->values()->toArray(); // ✅ convert to plain array here

        return $this->successResponse("Cli Numbers", $response);

    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to fetch SMS DID", [$exception->getMessage()], $exception, $exception->getCode());
    }
}


    public function smsDidListCRM()
    {
        // $this->validate($this->request, [
        //     'to' => 'required',
        //     'from' => 'required',
        //     'message' => 'required'
        // ]);
        try {
            $response = $this->model->smsDidListCRM($this->request);
            return response()->json($response);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS DID", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function smsResponse(Request $request)
    {
        $this->validate($request, [
            'client_id' => 'required|numeric|int',
            'user_id' => 'required|numeric|int',
            'from' => 'required|numeric',
            'to' => 'required|numeric',
            'message' => 'required'
        ]);


        //fetch package details
        $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;
        $clientId = $request->get('client_id');

        $user = new User();
        $user->id = $request->get('user_id');
        $user->parent_id = $clientId;
        $package = $user->getAssignedUserPackage(true);

        try {
            if (empty($package)) {
                //No charge for Admin
                $isFree = 1;
                $intCharge = 0;
            } else {
                //Calculate SMS charges
                if ($package->free_sms > 0) {
                    $isFree = 1;
                    $intCharge = 0;

                    //Deduct free balance
                    DB::connection('mysql_' . $clientId)->table('user_packages')->where('id', $package->user_package_id)->decrement('free_sms', 1);
                } else {
                    $intCharge = $package->rate_per_sms;
                    $isFree = 0;

                    //Deduct amount from client_xxx.wallet
                    wallet::debitCharge($intCharge, $clientId, $package->currency_code);
                }

                $currencyCode = $package->currency_code;
                $clientPackageId = $package->id;
            }

            //make entry into client_xxx.sms table
            $smsObj = new Sms();
            $smsObj->setConnection("mysql_" . $request->get('client_id'));
            $smsObj->extension = $request->get('user_id');
            $smsObj->number = $request->get('from');
            $smsObj->did = $request->get('to');
            $smsObj->message = $request->get('message');
            $smsObj->operator = $request->get('type'); //'didforsale';

            $smsObj->type = 'incoming';
            if ($request->has('sms_type'))
                $smsObj->sms_type = $request->get('sms_type');
            if ($request->has('mms_url'))
                $smsObj->mms_url = $request->get('mms_url');
            $smsObj->currency_code = $currencyCode ? $currencyCode : 'USD';
            $smsObj->client_package_id = $clientPackageId;
            $smsObj->user_id =  $request->get('user_id');
            $smsObj->charge = $intCharge;
            $smsObj->isFree = $isFree;
            $smsObj->save();

            // ========== PUSHER: Real-time SMS notification ==========
            try {
                $this->pusher->trigger('my-channel', 'my-event', [
                    'message' => [
                        'user_ids' => [$request->get('user_id')],
                        'platform' => 'text',
                        'msg' => 'New SMS from ' . $request->get('from'),
                        'from' => $request->get('from'),
                        'to' => $request->get('to'),
                        'body' => substr($request->get('message'), 0, 100),
                        'sms_id' => $smsObj->id,
                        'client_id' => $request->get('client_id')
                    ]
                ]);
                Log::info("Pusher SMS notification sent", [
                    'user_id' => $request->get('user_id'),
                    'from' => $request->get('from'),
                    'sms_id' => $smsObj->id
                ]);
            } catch (\Exception $e) {
                Log::error("Pusher SMS notification failed: " . $e->getMessage());
            }
            // ========== END PUSHER ==========

            //email sender

            $userDetails = User::findOrFail($request->get('user_id'));
            $receive_sms_on_email = $userDetails->receive_sms_on_email;
            $receive_sms_on_mobile = $userDetails->receive_sms_on_mobile;
            $sms_mobile = $userDetails->mobile;
            $country_code = $userDetails->country_code;


            $sms_email = $userDetails->email;
            $clientId = $request->get('client_id');

            if ($receive_sms_on_email == 1) {
                $senderTypeId = $request->get('user_id');
                try {
                    $smtpSetting = SmtpSetting::getBySenderType("mysql_" . $clientId, 'user', $senderTypeId);
                } catch (\Throwable $exception) {
                    $smtpSetting = SmtpSetting::getBySenderType("mysql_$clientId", "system");
                }

                //return $smtpSetting;

                $from = ["address" =>  $request->get('to') . '@' . env('DEFAULT_COMPANY_NAME') . '.com', "name" => ""];
                $subject = "SMS[" . $request->get('from') . "]";
                $genericMail = new GenericMail($subject, $from, $request->get('message'));
                $to = $sms_email;

                //send email
                $mailService = new MailService($request->get('client_id'), $genericMail, $smtpSetting);
                $mailService->sendEmail($to);
                //Log::debug("SendNotificationForReceiveSMSEmail.sendMessage.response", [$mailService]);

            }

            if ($receive_sms_on_mobile == 1) {
                if ($request->get('type') == 'plivo') {
                    $message = "SMS[" . $request->get('from') . "] " . $request->get('message');
                    $to = $country_code . $sms_mobile; //"917756966016";//;
                    $data_array['from'] = $request->get('to'); //env('PLIVO_SMS_NUMBER');
                    $plivo_user = env('PLIVO_USER');
                    $plivo_pass = env('PLIVO_PASS');

                    $client = new RestClient($plivo_user, $plivo_pass);
                    $result = $client->messages->create([
                        "src" => $data_array['from'],
                        "dst" => $to,
                        "text"  => $message,
                        "url" => ""
                    ]);

                    Log::debug("SendNotificationForReceiveSMSMobilePlivo.sendMessage.response", [$result]);
                } else {

                    $data_array = array();
                    $to = $country_code . $sms_mobile;

                    $data_array['to'] = $to;
                    $message = "SMS[" . $request->get('from') . "] " . $request->get('message');
                    $data_array['text'] = $message;
                    $data_array['from'] = $request->get('to');
                    $json_data_to_send = json_encode($data_array);

                    $setting = config("otp.sms");

                    //return $setting;


                    $api = $setting["key"];
                    $access = $setting["token"];
                    $sms_url = $setting["url"];




                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $sms_url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data_to_send);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode("$api:$access")));

                    $result = curl_exec($ch);
                    $res = json_decode($result);


                    //return $request->all();
                    /*$setting = config("otp.sms");
                    $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);


                    return $message = "SMS[".$request->get('from')."] ".$request->get('message');
                    $to = $country_code.$sms_mobile;//"917756966016";//;
                    $from_number = $request->get('to');//'19029155082';
                    $response = $smsService->sendMessage($from_number,$to,$message);*/
                    Log::debug("SendNotificationForReceiveSMSMobileDidforsale.sendMessage.response", [$res]);
                }
            }

            return array(
                'success' => true,
                'message' => "Successfully updated."
            );
        } catch (\Throwable $exception) {
            return array(
                'success' => false,
                'message' => "Failed to update."
            );
        }
    }
}
