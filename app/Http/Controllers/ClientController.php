<?php

namespace App\Http\Controllers;

use App\Jobs\CreateClientJob;
use App\Model\Client\UserPackage;
use App\Model\Client\wallet;
use App\Model\Client\walletTransaction;
use App\Model\Master\AsteriskServer;
use App\Model\Master\Client;
use App\Model\Master\ClientPackage;
use App\Model\Master\ClientServers;
use App\Model\Master\EmailVerification;
use App\Model\Master\Order;
use App\Model\Master\OrdersItem;
use App\Model\Master\Package;
use App\Model\Master\PaymentTransaction;
use App\Model\Master\PhoneVerification;
use App\Model\Master\WebPhoneVerification;
use App\Model\Master\WebLeads;


use App\Model\Master\Prospect;
use App\Model\User;
use Doctrine\DBAL\Driver\PDOException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Services\ClientService;
use App\Services\SmsService;
use App\Services\MailService;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SmsProviders;
use App\Services\PjsipRealtimeService;
use Illuminate\Support\Facades\DB;
use App\Mail\SystemNotificationMail;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Exceptions\TwilioException;
use Illuminate\Support\Str;

class ClientController extends Controller
{

    /**
     * @OA\Get(
     *     path="/clients",
     *     summary="Get all clients",
     *     description="Fetches a list of all clients.",
     *     tags={"Client"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of clients retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="client list"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Acme Corp"),
     *                     @OA\Property(property="email", type="string", example="contact@acme.com"),
     *                     @OA\Property(property="status", type="string", example="active")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Something went wrong"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        $clients = Client::all();
        return $this->successResponse("client list", $clients->toArray());
    }


    /**
     * @OA\Put(
     *     path="/client",
     *     summary="Create a new client",
     *     description="Registers a new client with necessary configurations and dispatches setup jobs.",
     *     tags={"Client"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"company_name", "asterisk_servers", "trunk", "enable_2fa", "api_key"},
     *             @OA\Property(property="company_name", type="string", example="Acme Corp"),
     *             @OA\Property(property="asterisk_servers", type="array", @OA\Items(type="integer"), example={1, 2}),
     *             @OA\Property(property="trunk", type="string", example="SIP-Trunk-1"),
     *             @OA\Property(property="address_1", type="string", example="123 Business Street"),
     *             @OA\Property(property="address_2", type="string", example="Suite 456"),
     *             @OA\Property(property="logo", type="string", example="logo.png"),
     *             @OA\Property(property="client_id", type="integer", example=101),
     *             @OA\Property(property="enable_2fa", type="string", example="true"),
     *             @OA\Property(property="api_key", type="string", example="abcdef123456"),
     *             @OA\Property(property="mca_crm", type="string", example="crm_connection"),
     *             @OA\Property(property="sms", type="string", example="1"),
     *             @OA\Property(property="fax", type="string", example="1"),
     *             @OA\Property(property="chat", type="string", example="1"),
     *             @OA\Property(property="webphone", type="string", example="1")      
     *   )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="client created"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 example={"company_name": {"The company name field is required."}}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to create client"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $this->validate($request, [
            'company_name' => 'required|string|max:255|unique:master.clients',
            'asterisk_servers' => 'required|array',
            'trunk' => 'required|string|max:30',
            'address_1' => 'sometimes|required|string|min:1|max:255',
            'address_2' => 'sometimes|required|string|min:1|max:255',
            'logo' => ["sometimes", "required", "string", "min:1", "regex:/^.+\.(jpg|png)$/"],
            'client_id' => 'sometimes|required|int|unique:master.clients,id',
            'enable_2fa' => 'required|string',
            'api_key' => 'required|string',
            'mca_crm' => 'string',


        ]);
        $attributes = $request->all();
        if (isset($attributes["client_id"])) $attributes["id"] = $attributes["client_id"];
        $attributes["stage"] = Client::RECORD_SAVED;
        /** @var Client $client */
        $client = Client::create($attributes);

        #Give admin permission to the client creator
        /** @var User $user */
        $user = User::findOrFail($request->auth->id);
        $user->addPermission($client->id, 1);

        $client->stage = Client::ADMIN_ASSIGNED;
        $client->saveOrFail();

        dispatch(new CreateClientJob($client, $request["asterisk_servers"]))->onConnection("clients");

        return $this->successResponse("client created", $client->toArray());
    }
    /**
     * @OA\Get(
     *     path="/client/{id}",
     *     summary="Get client details",
     *     description="Fetches detailed information of a specific client by ID.",
     *     tags={"Client"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the client to retrieve",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Client info"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Acme Corp"),
     *                 @OA\Property(property="email", type="string", example="contact@acme.com"),
     *                 @OA\Property(property="status", type="string", example="active"),
     *                 @OA\Property(
     *                     property="clientServers",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=101),
     *                         @OA\Property(property="ip", type="string", example="192.168.1.100"),
     *                         @OA\Property(property="description", type="string", example="Main Asterisk Server")
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="asteriskServerList",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=201),
     *                         @OA\Property(property="label", type="string", example="Server 1")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Client not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No client with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to fetch client info"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */

    public function show(int $id)
    {
        try {
            /** @var Client $client */
            $client = Client::findOrFail($id);
            $data = $client->toArray();
            $data["clientServers"] = $client->getAsteriskServers();
            $data["asteriskServerList"] = AsteriskServer::list();
            return $this->successResponse("Client info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No client with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch client info", [], $exception);
        }
    }


    /**
     * @OA\Post(
     *     path="/client/{id}",
     *     summary="Update an existing client",
     *     description="Updates client details including associated Asterisk servers and configuration settings.",
     *     tags={"Client"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the client to be updated",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"trunk", "api_key"},
     *             @OA\Property(property="company_name", type="string", example="Acme Corporation"),
     *             @OA\Property(property="trunk", type="string", example="SIP-Trunk-Updated"),
     *             @OA\Property(property="address_1", type="string", example="456 New Street"),
     *             @OA\Property(property="address_2", type="string", example="Suite 789"),
     *             @OA\Property(property="logo", type="string", example="updated_logo.png"),
     *             @OA\Property(property="asterisk_servers", type="array", @OA\Items(type="integer"), example={3, 4}),
     *             @OA\Property(property="enable_2fa", type="string", example="true"),
     *             @OA\Property(property="api_key", type="string", example="updatedapikey123456"),
     *             @OA\Property(property="mca_crm", type="string", example="updated_crm_info"),
     *             @OA\Property(property="sms", type="string", example="1"),
     *             @OA\Property(property="fax", type="string", example="1"),
     *             @OA\Property(property="chat", type="string", example="1"),
     *             @OA\Property(property="webphone", type="string", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Client updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Client not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No client with id 1")
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
     *                 example={"api_key": {"The api key field is required."}}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to update client"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'company_name' => 'sometimes|required|string|max:255',
            'trunk' => 'required|string|max:30',
            'address_1' => 'sometimes|required|string|min:1|max:255',
            'address_2' => 'sometimes|required|string|min:1|max:255',
            'logo' => ["sometimes", "required", "string", "min:1", "regex:/^.+\.(jpg|png)$/"],
            'asterisk_servers' => 'sometimes|required|array',
            'asterisk_servers.*' => 'sometimes|required|integer',
            'enable_2fa' => 'string',
            'api_key' => 'required|string',
            'mca_crm' => 'string',
            'ringless' => 'string',
            'callchex' => 'string',
            'predictive_dial' => 'string',


        ]);
        $input = $request->all();
        try {
            /** @var Client $client */
            $client = Client::findOrFail($id);
            $client->update($input);

            if (isset($input["asterisk_servers"])) {
                $new = $input["asterisk_servers"];
                $old = [];
                $servers = ClientServers::where("client_id", "=", $id)->get()->all();

                #remove servers
                foreach ($servers as $server) {
                    if (!in_array($server->server_id, $new)) {
                        $server->delete();
                    } else {
                        array_push($old, $server->server_id);
                    }
                }

                #add selected servers
                foreach ($new as $serverId) {
                    if (!in_array($serverId, $old)) {
                        $server = new ClientServers(["client_id" => $id, "server_id" => $serverId, "ip_address" => $serverId]);
                        $server->saveOrFail();
                    }
                }
            }
            $data = $client->toArray();
            $data["clientServers"] = $client->getAsteriskServers();
            $data["asteriskServerList"] = AsteriskServer::list();

            if ($client->stage < Client::ASSIGN_ASTERISK_SERVER) {
                dispatch(new CreateClientJob($client, $input["asterisk_servers"]))->onConnection("clients");
            }

            ClientService::clearCache();
            $user = User::findOrFail($request->auth->id);
            $permissions = $user->getPermissions(true);
            $data["userPermissions"] = $permissions;

            return $this->successResponse("Client updated", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No client with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update client", [], $exception);
        }
    }

    public function prospectSignup(Request $request)
    {
        $this->validate($request, [
            "first_name" => "required|string|max:255",
            "last_name" => "sometimes|string|max:255",
            "company_name" => "required|string",
            "country_code" => "required|numeric|min:1|max:9999",
            "mobile" => "required|digits_between:7,10",
            "email" => "required|email|unique:master.users,email",
            "password" => "required|min:6|max:64",
            "mobile_otp" => [
                "required",
                Rule::exists('master.phone_verifications', 'id')->where(function ($query) {
                    $query->where('status', 4);
                })
            ],
            "email_otp" => [
                "required",
                Rule::exists('master.email_verifications', 'id')->where(function ($query) {
                    $query->where('status', 4);
                })
            ]
        ]);

        $companyName = trim($request->get('company_name'));
        $prospect = Prospect::where("company_name", $companyName)->first();
        if (!empty($prospect)) {
            return $this->failResponse("Invalid input", ["company_name" => ["The company name has already been taken."]], null, 422);
        }

        $phoneVerification = PhoneVerification::findOrFail($request->get('mobile_otp'));
        if ($phoneVerification->phone_number != $request->get('mobile')) {
            return $this->failResponse("Invalid input", ["mobile_otp" => ["The selected mobile otp is invalid."]], null, 400);
        }

        $emailVerification = EmailVerification::findOrFail($request->get('email_otp'));
        if ($emailVerification->email != $request->get('email')) {
            return $this->failResponse("Invalid input", ["mobile_otp" => ["The selected email otp is invalid."]], null, 400);
        }

        $prospect = new Prospect();
        $prospect->first_name = $request->get('first_name');
        $prospect->country_code = $request->get('country_code');
        $prospect->mobile = $request->get('mobile');
        $prospect->email = $request->get('email');
        $prospect->password = Hash::make($request->get('password'));
        $prospect->status = Prospect::REGISTERED;
        $prospect->mobile_otp = $phoneVerification->id;
        $prospect->email_otp = $emailVerification->id;
        $prospect->company_name = $companyName;

        if ($request->has('last_name')) $prospect->last_name = $request->get('last_name');
        if ($request->has('address_1')) $prospect->address_1 = $request->get('address_1');
        if ($request->has('address_2')) $prospect->address_2 = $request->get('address_2');
        try {
            $prospect->saveOrFail();

            #Send SMS Notifcation
            $name = $request->get('first_name') . ' ' . $request->get('last_name');
            $setting = config("otp.sms");
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            $message = $name . "," . $request->get('country_code') . "-" . $request->get('mobile') . ", " . $request->get('email') . " from " . $request->get('company_name') . " Verified New Registration on website";
            $env_sms_number = env("SUPPORT_TEAM_TEXT_GROUP") . ',' . env('SALES_TEAM_TEXT_GROUP');
            $sms_number = explode(',', $env_sms_number);
            foreach ($sms_number as  $to) {
                $response = $smsService->sendMessage($setting["from_number"], $to, $message);
                Log::debug("SendNotificationOnSavingProspedctInitialData.sendMessage.response", [$response]);
            }

            $smtpSetting = new SmtpSetting;
            $smtpSetting->mail_driver = "SMTP";
            $smtpSetting->mail_host = env("PORTAL_MAIL_HOST");
            $smtpSetting->mail_port = env("PORTAL_MAIL_PORT");
            $smtpSetting->mail_username = env("PORTAL_MAIL_USERNAME");
            $smtpSetting->mail_password = env("PORTAL_MAIL_PASSWORD");
            $smtpSetting->from_name = env("PORTAL_MAIL_SENDER_NAME");
            $smtpSetting->from_email = env("PORTAL_MAIL_SENDER_EMAIL");
            $smtpSetting->mail_encryption = env("PORTAL_MAIL_ENCRYPTION");
            $from = [
                "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
            ];

            $this->data['action'] = 'Verified New user Notification-' . $request->get('mobile');
            $this->data["userInfo"]["name"] = $name;
            $this->data["userInfo"]["phone_number"] = $request->get('country_code') . '-' . $request->get('mobile');
            $this->data["userInfo"]["email"] = $request->get('email');
            $this->data["userInfo"]["company_name"] = $request->get('company_name');
            $this->clientId = '0';

            //SYSTEM_ADMIN_EMAIL
            $mailable = new SystemNotificationMail($from, "emails.registrationAction", $this->data["action"], $this->data);
            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $SYSTEM_ADMIN_EMAIL = explode(',', env('SYSTEM_ADMIN_EMAIL'));
            $responseEmail = $mailService->sendEmail($SYSTEM_ADMIN_EMAIL);


            //SUPPORT_TEAM_EMAIL_GROUP
            $mailable = new SystemNotificationMail($from, "emails.registrationAction", $this->data["action"], $this->data);
            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $SUPPORT_TEAM_EMAIL_GROUP = explode(',', env('SUPPORT_TEAM_EMAIL_GROUP'));
            $responseEmail = $mailService->sendEmail($SUPPORT_TEAM_EMAIL_GROUP);

            //SALES_TEAM_EMAIL_GROUP
            $mailable = new SystemNotificationMail($from, "emails.registrationAction", $this->data["action"], $this->data);
            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $SALES_TEAM_EMAIL_GROUP = explode(',', env('SALES_TEAM_EMAIL_GROUP'));
            $responseEmail = $mailService->sendEmail($SALES_TEAM_EMAIL_GROUP);

            Log::debug("SendNotificationOnVarifiedSavingProspedctInitialData.sendEmail.responseEmail", [$responseEmail]);
            return $this->successResponse("Registered successfully", $prospect->toArray());
        } catch (QueryException $queryException) {
            $context = buildContext($queryException);
            $context["request"] = $request->all();
            if (isset($context["request"]["password"])) unset($context["request"]["password"]);
            Log::critical("ClientController.signup failed. QueryException", $context);

            $previous = $queryException->getPrevious();

            if ($previous instanceof PDOException) {
                if ($previous->getErrorCode() == 1062) {
                    return $this->failResponse("Invalid input", ["email" => ["The email has already been taken."]], $previous, 400);
                }
            }

            return $this->failResponse("Failed to save the record", ["Please contact support."], $previous, 500);
        } catch (\Throwable $exception) {
            $context = buildContext($exception);
            $context["request"] = $request->all();
            if (isset($context["request"]["password"])) unset($context["request"]["password"]);
            Log::critical("ClientController.signup failed", $context);
            return $this->failResponse("Failed to register", ["Please contact support."], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/client/manual-subscription",
     *     summary="Manually assign a subscription package to a client",
     *     description="Creates billing, order, transaction, client_package, and user_package records based on the provided package key and billing cycle.",
     *     tags={"Client"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"package", "client_id", "quantity"},
     *             @OA\Property(property="package", type="string", example="588703ba-e78a-430f-8872-bb088dc1abba"),
     *             @OA\Property(property="client_id", type="integer", example=5),
     *             @OA\Property(property="quantity", type="integer", example=2),
     *             @OA\Property(property="billing", type="integer", example=1, description="Required if not a trial package")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Subscription successfully assigned",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Basic Plan subscription added successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Incorrect package key provided",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Incorrect package key provided")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 example={"billing": {"The billing field is required."}}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to assign subscription"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */

    public function performManualSubscription(Request $request)
    {

        $this->validate($request, [
            'package' => 'required|string',
            'client_id' => 'required|integer',
            'quantity' => 'required|integer',
            'billing' => [
                "integer",
                Rule::requiredIf($request->get("package") != "588703ba-e78a-430f-8872-bb088dc1abba"),
            ]
        ]);

        try {
            $intQuantity = $intBilled = NULL;

            //validate package key
            $packages = Package::all()->toArray();
            $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'key');

            if (!array_key_exists($request->get("package"), $packagesRekeyed)) {
                throw new NotFoundHttpException("Incorrect package key provided");
            }

            if ($request->get("package") == Package::TRIAL_PACKAGE_KEY) {
                $strEndDate =  Carbon::now()->addDays(7);
                $intBilled = 1;
            } else {
                $strEndDate = ClientPackage::getEndDateAsPerBillingCycle($request->get("billing"));
                $intBilled = $request->get("billing");
            }

            /////////////////////////////
            ////// Billing Entries //////
            /////////////////////////////

            $arrPackage = $packagesRekeyed[$request->get("package")];
            $intQuantity = $request->get("quantity");
            $intTotalAmount = $intQuantity * $arrPackage[ClientPackage::$billingMapping[$intBilled]];

            //Entry into order
            $objOrder = new Order();
            $objOrder->client_id = $request->get("client_id");
            $objOrder->net_amount = $intTotalAmount;
            $objOrder->gross_amount = $intTotalAmount;
            $objOrder->status = 'success';
            $objOrder->saveOrFail();

            //Entry into order_items
            $objOrdersItem = new OrdersItem();
            $objOrdersItem->order_id = $objOrder->id;
            $objOrdersItem->description = 'package purchase';
            $objOrdersItem->package_key = $arrPackage['key'];
            $objOrdersItem->quantity = $intQuantity;
            $objOrdersItem->billed = $intBilled;
            $objOrdersItem->amount = $intTotalAmount;
            $objOrdersItem->saveOrFail();

            //Entry into payment_transactions
            $objPaymentTransactions = new PaymentTransaction();
            $objPaymentTransactions->order_id = $objOrder->id;
            $objPaymentTransactions->payment_gateway_type = 'manual';
            $objPaymentTransactions->status = 'success';
            $objPaymentTransactions->saveOrFail();

            //////////////////////////////
            //// Subscription Entries ////
            //////////////////////////////

            //Entry into client_packages
            $objClientPackage = new ClientPackage();
            $objClientPackage->client_id = $request->get("client_id");
            $objClientPackage->package_key = $arrPackage['key'];
            $objClientPackage->quantity = $intQuantity;
            $objClientPackage->start_time = Carbon::now();
            $objClientPackage->end_time = $strEndDate;
            $objClientPackage->expiry_time = $strEndDate;
            $objClientPackage->billed = $intBilled;
            $objClientPackage->payment_cent_amount = $intTotalAmount * 100;
            $objClientPackage->payment_time = Carbon::now();
            $objClientPackage->payment_method = "Manual";
            $objClientPackage->psp_reference = time();
            $objClientPackage->saveOrFail();

            //Entry into user_packages
            for ($i = 0; $i < $intQuantity; $i++) {
                $objUserPackage = new UserPackage();
                $objUserPackage->setConnection('mysql_' . $request->get("client_id"));
                $objUserPackage->client_package_id = $objClientPackage->id;
                $objUserPackage->free_call_minutes = $arrPackage['free_call_minute_monthly'];
                $objUserPackage->free_sms = $arrPackage['free_sms_monthly'];
                $objUserPackage->free_fax = $arrPackage['free_fax_monthly'];
                $objUserPackage->free_emails = $arrPackage['free_emails_monthly'];
                $objUserPackage->free_reset_time = Carbon::now()->addMonth();
                $objUserPackage->saveOrFail();
            }

            return $this->successResponse($arrPackage['name'] . " subscription added successfully", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to assign subscription", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Post(
     *     path="/client/credit-wallet",
     *     summary="Credit client wallet manually",
     *     description="Credits the client's wallet with a specified amount in USD and logs the transaction.",
     *     tags={"Wallet"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount"},
     *             @OA\Property(property="amount", type="integer", example=100)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Wallet credited successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Wallet credited successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 example={"amount": {"The amount field is required."}}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to credit"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */


    public function creditWallet(Request $request)
    {
        $this->validate($request, [
            'amount' => 'required|integer'
        ]);

        try {
            //Add amount into client_xxx.wallet
            wallet::creditCharge($request->get("amount"), $request->auth->parent_id, 'USD');

            //ledger entry into client_xxx.wallet_transactions
            $objWalletTransaction = new WalletTransaction();
            $objWalletTransaction->setConnection("mysql_" . $request->auth->parent_id);
            $objWalletTransaction->currency_code = 'USD';
            $objWalletTransaction->amount = $request->get("amount");
            $objWalletTransaction->transaction_type = 'credit';
            $objWalletTransaction->transaction_reference = time();
            $objWalletTransaction->description = 'Manual';
            $objWalletTransaction->saveOrFail();

            return $this->successResponse(" Wallet credited successfully", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to credit", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Put(
     *     path="/sms-provider/{id}",
     *     summary="Create or update an SMS provider",
     *     description="Creates a new SMS provider or updates an existing one for a given client. Also handles related SIP user extension setup.",
     *     tags={"Client"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="The ID of the client (used to determine the database connection)",
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"api_key", "provider"},
     *             @OA\Property(property="auth_id", type="string", example="auth-abc123"),
     *             @OA\Property(property="api_key", type="string", example="key-xyz123"),
     *             @OA\Property(property="provider", type="string", example="twilio"),
     *             @OA\Property(property="label_name", type="string", example="Twilio Line"),
     *             @OA\Property(property="host", type="string", example="sip.twilio.com"),
     *             @OA\Property(property="sip_username", type="string", example="twiliouser"),
     *             @OA\Property(property="sip_password", type="string", example="securePass123"),
     *            @OA\Property(property="sms_ai_url", type="string", example="OIJQW"),
     *             @OA\Property(property="domain_api_url", type="string", example="OIJQW"),
     *             @OA\Property(property="access_token", type="string", example="oijqw")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Provider created or updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SMS Provider created"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to create/update SMS provider"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string")
     *         )
     *     )
     * )
     */


    //sms provider
    public function createSmsProvider(Request $request, int $id)
    {

        Log::info('reached', $request->all());
        //return $request->all();die;

        $this->validate($request, [
            'auth_id' => 'string',
            'api_key' => 'required|string',
            'provider' => 'required|string',

        ]);

        $clients = SmsProviders::on("mysql_" . $id)->where('status', '1')->where('provider', $request->provider)->get()->first();

        if ($clients) {
            $input = $request->all();
            $clients->update($input);

            if (!empty($clients->user_extension_id)) {
                $name = str_replace(" ", "-", $request->label_name);

                if (!empty($request->sip_username))
                    $dt['username'] = $request->sip_username;
                else
                    $dt['username'] = $name;

                if (!empty($request->sip_password))
                    $dt['secret'] = $request->sip_password;
                else
                    $dt['secret'] = '';

                $dt['host'] = $request->host;
                $dt['name'] = $name;
                $dt['id'] = $clients->user_extension_id;
                $dt['fullname'] = $name;

                $insertData = "UPDATE user_extensions SET username= :username , host= :host,name=:name, secret=:secret,fullname=:fullname WHERE id= :id ";
                $record_ustext = DB::connection('master')->select($insertData, $dt);

                // Sync PJSIP realtime after client extension update
                if (!empty($dt['secret'])) {
                    PjsipRealtimeService::syncPassword($dt['username'], $dt['secret']);
                }
            } else {
                $dt['disallow'] = 'all';
                $dt['allow'] = 'ulaw;alaw;gsm;g729';
                $dt['context'] = 'trunkinbound-airespring';

                $name = str_replace(" ", "-", $request->label_name);
                $dt['host'] = $request->host;
                $dt['name'] = $name;
                $dt['nat'] = 'force_rport,comedia';
                if (!empty($request->sip_username))
                    $dt['username'] = $request->sip_username;
                else
                    $dt['username'] = $name;
                if (!empty($request->sip_password))
                    $dt['secret'] = $request->sip_password;
                else
                    $dt['secret'] = '';
                $dt['fullname'] = $name;

                $insertData = "INSERT INTO user_extensions SET  disallow=:disallow, allow=:allow, context= :context, username=:username, host=:host, name= :name, nat= :nat , secret= :secret, fullname= :fullname";
                $record_ustextSav = DB::connection('master')->select($insertData, $dt);

                // Sync PJSIP realtime after client extension create
                PjsipRealtimeService::syncExtension($dt['username'], $dt['secret'], $dt['context'], $dt['fullname']);

                $lastInsertId = DB::connection('master')->selectOne("SELECT * FROM user_extensions ORDER BY id DESC");
                $lastId = $lastInsertId->id;

                $new_input['user_extension_id'] = $lastId;
                $clients->update($new_input);
            }

    // 🔥 TWILIO SHOULD RUN HERE
    if (strtolower(trim($clients->provider)) === 'twilio') {

        Log::info('Twilio update flow reached', [
            'provider_id' => $clients->id
        ]);

        $accountSid = $clients->auth_id;
        $authToken  = $clients->api_key;

        if ($accountSid && $authToken && empty($clients->twilio_trunk_sid)) {
            try {
                $sipUrl = env('TWILIO_SIP_URL');

                $twilioClient = new \Twilio\Rest\Client($accountSid, $authToken);

                $friendlyName = 'phonify-demo-crm-iocod-'
                    . $id . '-'
                    . Carbon::now()->timestamp;

                $trunk = $twilioClient->trunking
                    ->v1
                    ->trunks
                    ->create(['friendlyName' => $friendlyName]);

                $twilioClient->trunking
                    ->v1
                    ->trunks($trunk->sid)
                    ->originationUrls
                    ->create(10, 10, true, $friendlyName, $sipUrl);

                $clients->update([
                    'twilio_trunk_sid' => $trunk->sid
                ]);

                Log::info('Twilio SIP trunk created on update', [
                    'provider_id' => $clients->id,
                    'trunk_sid'   => $trunk->sid
                ]);

            } catch (\Twilio\Exceptions\TwilioException $e) {
                Log::error('Twilio SIP trunk failed on update', [
                    'provider_id' => $clients->id,
                    'error'       => $e->getMessage()
                ]);
            }
        }
    }



            return $this->successResponse("SMS Provider Updated", $clients->toArray());
        } else {

            $dt['disallow'] = 'all';
            $dt['allow'] = 'ulaw;alaw;gsm;g729';
            $dt['context'] = 'trunkinbound-airespring';

            $name = str_replace(" ", "-", $request->label_name);
            $dt['host'] = $request->host;
            $dt['name'] = $name;
            $dt['nat'] = 'force_rport,comedia';
            if (!empty($request->sip_username))
                $dt['username'] = $request->sip_username;
            else
                $dt['username'] = $name;

            if (!empty($request->sip_password))
                $dt['secret'] = $request->sip_password;
            else
                $dt['secret'] = '';
            $dt['fullname'] = $name;

            $insertData = "INSERT INTO user_extensions SET  disallow=:disallow, allow=:allow, context= :context, username=:username, host=:host, name= :name, nat= :nat , secret= :secret, fullname= :fullname";
            $record_ustextSav = DB::connection('master')->select($insertData, $dt);

            // Sync PJSIP realtime after new provider extension create
            PjsipRealtimeService::syncExtension($dt['username'], $dt['secret'], $dt['context'], $dt['fullname']);

            $lastInsertId = DB::connection('master')->selectOne("SELECT * FROM user_extensions ORDER BY id DESC");
            $lastId = $lastInsertId->id;

            $request['user_extension_id'] = $lastId;
            $attributes = $request->all();

            Log::info('domain_url', ['attributes' => $attributes]);

            /** @var Client $client */
            $client = SmsProviders::on("mysql_" . $id)->create($attributes);
 
if (strtolower(trim($client->provider)) === 'twilio') {

    Log::info('Twilio create flow reached', [
        'provider_id' => $client->id
    ]);

    $accountSid = $client->auth_id;
    $authToken  = $client->api_key;

    if ($accountSid && $authToken && empty($client->twilio_trunk_sid)) {
        try {
            $sipUrl = env('TWILIO_SIP_URL');

            $twilioClient = new \Twilio\Rest\Client($accountSid, $authToken);

            $friendlyName = 'phonify-demo-crm-iocod-'
                . $id . '-'
                . Carbon::now()->timestamp;

            $trunk = $twilioClient->trunking
                ->v1
                ->trunks
                ->create(['friendlyName' => $friendlyName]);

            $twilioClient->trunking
                ->v1
                ->trunks($trunk->sid)
                ->originationUrls
                ->create(10, 10, true, $friendlyName, $sipUrl);

            $client->update([
                'twilio_trunk_sid' => $trunk->sid
            ]);

            Log::info('Twilio SIP trunk created on create', [
                'provider_id' => $client->id,
                'trunk_sid'   => $trunk->sid
            ]);

        } catch (\Twilio\Exceptions\TwilioException $e) {
            Log::error('Twilio SIP trunk failed on create', [
                'provider_id' => $client->id,
                'error'       => $e->getMessage()
            ]);
        }
    }
}

            return $this->successResponse("SMS Provider created", $client->toArray());
        }
    }

    /**
     * @OA\Get(
     *     path="/sms-provider/{id}",
     *     summary="Get active SMS providers for a client",
     *     description="Returns a list of active SMS providers  for the given client .",
     *     tags={"Client"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Client ID to fetch SMS providers from the respective database",
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of active SMS providers",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="client list"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch SMS providers",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS providers"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string")
     *         )
     *     )
     * )
     */


    public function showSmsProvider(int $id)
    {
        $clients = SmsProviders::on("mysql_" . $id)->where('status', '1')->get()->all();
        return $this->successResponse("client list", $clients);
    }


    public function WebsiteLeadSignup(Request $request)
    {
        $this->validate($request, [
            "first_name" => "required|string|max:255",
            "last_name" => "sometimes|string|max:255",
            //"company_name" => "required|string",
            "country_code" => "required|numeric|min:1|max:9999",
            "phone_number" => "required|digits_between:7,10",
            "email" => "required|email",
            //"password" => "required|min:6|max:64",
            "uuid" => [
                "required",
                Rule::exists('master.web_phone_verifications', 'id')->where(function ($query) {
                    $query->where('status', 4);
                })
            ],
            /*"email_otp" => [
                "required",
                Rule::exists('master.email_verifications','id')->where(function ($query) {
                    $query->where('status', 4);
                })
            ]*/
        ]);

        //$companyName = trim($request->get('company_name'));
        //$prospect = Prospect::where("company_name", $companyName)->first();
        //if (!empty($prospect)) {
        //  return $this->failResponse("Invalid input", ["company_name" => ["The company name has already been taken."]], null, 422);
        //}

        $phoneVerification = WebPhoneVerification::findOrFail($request->get('uuid'));
        if ($phoneVerification->phone_number != $request->get('phone_number')) {
            return $this->failResponse("Invalid input", ["uuid" => ["The selected uuid is invalid."]], null, 400);
        }

        /*$emailVerification = EmailVerification::findOrFail($request->get('email_otp'));
        if ($emailVerification->email != $request->get('email')) {
            return $this->failResponse("Invalid input", ["mobile_otp" => ["The selected email otp is invalid."]], null, 400);
        }*/

        $prospect = new WebLeads();
        $prospect->first_name = $request->get('first_name');
        $prospect->country_code = $request->get('country_code');
        $prospect->mobile = $request->get('phone_number');
        $prospect->email = $request->get('email');
        //$prospect->password = Hash::make($request->get('password'));
        $prospect->status = Prospect::REGISTERED;
        $prospect->mobile_otp = $phoneVerification->id;
        //$prospect->email_otp = $emailVerification->id;
        //$prospect->company_name = $companyName;

        //if ($request->has('last_name')) $prospect->last_name = $request->get('last_name');
        //if ($request->has('address_1')) $prospect->address_1 = $request->get('address_1');
        //if ($request->has('address_2')) $prospect->address_2 = $request->get('address_2');
        try {
            $prospect->saveOrFail();

            #Send SMS Notifcation
            $name = $request->get('first_name') . ' ' . $request->get('last_name');
            $setting = config("otp.sms");
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            $message = $name . "," . $request->get('country_code') . "-" . $request->get('mobile') . ", " . $request->get('email') . " from " . $request->get('company_name') . " Verified New Registration on website";
            $env_sms_number = env("SUPPORT_TEAM_TEXT_GROUP") . ',' . env('SALES_TEAM_TEXT_GROUP');
            $sms_number = explode(',', $env_sms_number);
            foreach ($sms_number as  $to) {
                $response = $smsService->sendMessage($setting["from_number"], $to, $message);
                Log::debug("SendNotificationOnSavingProspedctInitialData.sendMessage.response", [$response]);
            }

            $smtpSetting = new SmtpSetting;
            $smtpSetting->mail_driver = "SMTP";
            $smtpSetting->mail_host = env("PORTAL_MAIL_HOST");
            $smtpSetting->mail_port = env("PORTAL_MAIL_PORT");
            $smtpSetting->mail_username = env("PORTAL_MAIL_USERNAME");
            $smtpSetting->mail_password = env("PORTAL_MAIL_PASSWORD");
            $smtpSetting->from_name = env("PORTAL_MAIL_SENDER_NAME");
            $smtpSetting->from_email = env("PORTAL_MAIL_SENDER_EMAIL");
            $smtpSetting->mail_encryption = env("PORTAL_MAIL_ENCRYPTION");
            $from = [
                "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
            ];

            $this->data['action'] = 'Verified New user Notification-' . $request->get('mobile');
            $this->data["userInfo"]["name"] = $name;
            $this->data["userInfo"]["phone_number"] = $request->get('country_code') . '-' . $request->get('mobile');
            $this->data["userInfo"]["email"] = $request->get('email');
            $this->data["userInfo"]["company_name"] = $request->get('company_name');
            $this->clientId = '0';

            //SYSTEM_ADMIN_EMAIL
            $mailable = new SystemNotificationMail($from, "emails.registrationAction", $this->data["action"], $this->data);
            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $SYSTEM_ADMIN_EMAIL = explode(',', env('SYSTEM_ADMIN_EMAIL'));
            $responseEmail = $mailService->sendEmail($SYSTEM_ADMIN_EMAIL);


            //SUPPORT_TEAM_EMAIL_GROUP
            /*$mailable = new SystemNotificationMail($from, "emails.registrationAction", $this->data["action"], $this->data);
            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $SUPPORT_TEAM_EMAIL_GROUP = explode(',', env('SUPPORT_TEAM_EMAIL_GROUP'));
            $responseEmail = $mailService->sendEmail($SUPPORT_TEAM_EMAIL_GROUP);

            //SALES_TEAM_EMAIL_GROUP
            $mailable = new SystemNotificationMail($from, "emails.registrationAction", $this->data["action"], $this->data);
            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $SALES_TEAM_EMAIL_GROUP = explode(',', env('SALES_TEAM_EMAIL_GROUP'));
            $responseEmail = $mailService->sendEmail($SALES_TEAM_EMAIL_GROUP);*/

            Log::debug("SendNotificationOnVarifiedSavingProspedctInitialData.sendEmail.responseEmail", [$responseEmail]);
            return $this->successResponse("Registered successfully", $prospect->toArray());
        } catch (QueryException $queryException) {
            $context = buildContext($queryException);
            $context["request"] = $request->all();
            if (isset($context["request"]["password"])) unset($context["request"]["password"]);
            Log::critical("ClientController.signup failed. QueryException", $context);

            $previous = $queryException->getPrevious();

            if ($previous instanceof PDOException) {
                if ($previous->getErrorCode() == 1062) {
                    return $this->failResponse("Invalid input", ["email" => ["The email has already been taken."]], $previous, 200);
                }
            }

            return $this->failResponse("Failed to save the record", ["Please contact support."], $previous, 200);
        } catch (\Throwable $exception) {
            $context = buildContext($exception);
            $context["request"] = $request->all();
            if (isset($context["request"]["password"])) unset($context["request"]["password"]);
            Log::critical("ClientController.signup failed", $context);
            return $this->failResponse("Failed to register", ["Please contact support."], $exception);
        }
    }
}
