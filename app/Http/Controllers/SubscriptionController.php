<?php

namespace App\Http\Controllers;

use App\Jobs\ConvertProspectToClient;
use App\Model\Master\Module;
use App\Model\Master\ProspectPackage;
use App\Model\Master\Package;
use App\Model\Master\Client;
use App\Model\Master\Permission;
use App\Model\User;

use App\Model\Master\Prospect;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use App\Model\Master\ProspectInitialData;
use App\Services\SmsService;
use App\Services\MailService;
use App\Model\Client\SmtpSetting;
use App\Mail\SystemNotificationMail;




class SubscriptionController extends Controller
{

    /**
     * @OA\Get(
     *     path="/packages",
     *     summary="Get list of packages",
     *     tags={"Subscriptions"},
     *     security={{"Bearer": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with package data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="List of packages."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function list(Request $request)
    {
        $this->validate($request, [
            "show_on" => "sometimes|in:website,portal",
            "applicable_for" => "sometimes|in:1,2",
        ]);

        $where = [["is_active", "=", 1]];
        if ($request->has("applicable_for")) {
            $where[] = ["applicable_for", "=", $request->get("applicable_for")];
        }

        $packages = Package::where($where)->get()->sortBy("display_order")->all();
        $result = [];
        foreach ($packages as $package) {
            $output = $package->toArray();
            #if show_on filter passed evaluate and skip this package
            if ($request->has("show_on")) {
                if (!in_array($request->get("show_on"), $package->show_on)) continue;
            }

            $modules = Module::whereIn("key", $package->modules)->get()->sortBy("display_order")->all();
            $output["modules"] = $modules;
            $result[] = $output;
        }
        return $this->successResponse("Packages", $result);
    }

    public function saveProspectPackage(Request $request)
    {
        $this->validate($request, [
            "prospect_id" => "required|numeric|exists:master.prospects,id",
            "package_key" => [
                "required",
                Rule::exists('master.packages', 'key')->where(function ($query) {
                    $query->where('is_active', 1);
                })
            ],
            "quantity" => "required|int",
            "start_time" => "required|date",
            "end_time" => "required|date",
            "expiry_time" => "required|date",
            "billed" => "required|int",
            "payment_cent_amount" => "required|int",
            "payment_time" => "required|date",
            "payment_method" => "required|string",
            "psp_reference" => "required|string"
        ]);

        Log::debug("saveProspectPackage", $request->all());

        try {
            $prospectPackage = ProspectPackage::findOrFail([
                'prospect_id' => $request->get('prospect_id'),
                'package_key' => $request->get('package_key')
            ]);
        } catch (ModelNotFoundException $notFoundException) {
            $prospectPackage = new ProspectPackage();
            $prospectPackage->prospect_id = $request->get('prospect_id');
            $prospectPackage->package_key = $request->get('package_key');
            $prospectPackage->quantity = $request->get('quantity');
            $prospectPackage->start_time = Carbon::parse($request->get('start_time'));
            if ($request->has('end_time'))
                $prospectPackage->end_time = Carbon::parse($request->get('end_time'));
            if ($request->has('expiry_time'))
                $prospectPackage->expiry_time = Carbon::parse($request->get('expiry_time'));
            $prospectPackage->billed = $request->get('billed');
            $prospectPackage->payment_cent_amount = $request->get('payment_cent_amount');
            $prospectPackage->payment_method = $request->get('payment_method');
            $prospectPackage->payment_time = $request->get('payment_time');
            $prospectPackage->psp_reference = $request->get('psp_reference');
            $prospectPackage->saveOrFail();
        }

        $prospect = Prospect::find($prospectPackage->prospect_id);
        $prospect->status = 2;  #paid
        $prospect->saveOrFail();

        dispatch(new ConvertProspectToClient($prospectPackage->prospect_id, $prospectPackage->package_key))->onConnection("clients");

        return $this->successResponse("Subscription saved", $prospectPackage->toArray());
    }


    /**
     * @OA\Put(
     *     path="/package",
     *     summary="Create a new package",
     *     tags={"Subscriptions"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={
     *                 "name", "description", "is_active", "display_order", "applicable_for", 
     *                 "show_on", "modules", "currency_code", 
     *                 "base_rate_monthly_billed", "base_rate_quarterly_billed", 
     *                 "base_rate_half_yearly_billed", "base_rate_yearly_billed", 
     *                 "call_rate_per_minute", "rate_per_sms", "rate_per_did", 
     *                 "rate_per_fax", "rate_per_email"
     *             },
     *             @OA\Property(property="name", type="string", example="Pro Package"),
     *             @OA\Property(property="description", type="string", example="Full feature access"),
     *             @OA\Property(property="is_active", type="integer", example=1),
     *             @OA\Property(property="display_order", type="integer", example=1),
     *             @OA\Property(property="applicable_for", type="integer", example=2),
     *             @OA\Property(property="show_on", type="array", @OA\Items(type="string"), example={"web", "mobile"}),
     *             @OA\Property(property="modules", type="array", @OA\Items(type="string"), example={"billing", "support"}),
     *             @OA\Property(property="currency_code", type="string", example="USD"),
     *             @OA\Property(property="base_rate_monthly_billed", type="number", format="float", example=29.99),
     *             @OA\Property(property="base_rate_quarterly_billed", type="number", format="float", example=79.99),
     *             @OA\Property(property="base_rate_half_yearly_billed", type="number", format="float", example=149.99),
     *             @OA\Property(property="base_rate_yearly_billed", type="number", format="float", example=279.99),
     *             @OA\Property(property="call_rate_per_minute", type="number", format="float", example=0.05),
     *             @OA\Property(property="rate_per_sms", type="number", format="float", example=0.02),
     *             @OA\Property(property="rate_per_did", type="number", format="float", example=1.99),
     *             @OA\Property(property="rate_per_fax", type="number", format="float", example=0.01),
     *             @OA\Property(property="rate_per_email", type="number", format="float", example=0.005),
     *             @OA\Property(property="free_call_minute_monthly", type="integer", example=100),
     *             @OA\Property(property="free_sms_monthly", type="integer", example=200),
     *             @OA\Property(property="free_fax_monthly", type="integer", example=50),
     *             @OA\Property(property="free_emails_monthly", type="integer", example=500),
     *             @OA\Property(property="free_did_monthly", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Package created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Packages request created"),
     *             @OA\Property(property="data", type="object", example={
     *                 "id": 12,
     *                 "name": "Pro Package",
     *                 "currency_code": "USD",
     *                 "base_rate_monthly_billed": 29.99
     *             })
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation or saving error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to save the package request"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $this->validate($request, [
            "name" => "required|string",
            "description" => "required|string",
            "is_active" => "required|int",
            "display_order" => "required|int",
            "applicable_for" => "required|int",
            "show_on" => "required|array",
            "modules" => "required|array",
            "currency_code" => "required|string",
            "base_rate_monthly_billed" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "base_rate_quarterly_billed" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "base_rate_half_yearly_billed" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "base_rate_yearly_billed" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "call_rate_per_minute" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_sms" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_did" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_fax" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_email" => "required|regex:/^\d*(\.\d{1,5})?$/",
        ]);

        Log::debug("savePackage", $request->all());

        try {
            $package = new Package();
            $package->key = Str::uuid()->toString();
            $package->name = $request->name;
            $package->description = $request->description;
            $package->is_active = $request->is_active;
            $package->display_order = $request->display_order;
            $package->applicable_for = $request->applicable_for;
            $package->show_on = $request->show_on;
            $package->modules = $request->modules;
            $package->currency_code = $request->currency_code;
            $package->base_rate_monthly_billed = $request->base_rate_monthly_billed;
            $package->base_rate_quarterly_billed = $request->base_rate_quarterly_billed;
            $package->base_rate_half_yearly_billed = $request->base_rate_half_yearly_billed;
            $package->base_rate_yearly_billed = $request->base_rate_yearly_billed;
            $package->call_rate_per_minute = $request->call_rate_per_minute;
            $package->rate_per_sms = $request->rate_per_sms;
            $package->rate_per_did = $request->rate_per_did;
            $package->rate_per_fax = $request->rate_per_fax;
            $package->rate_per_email = $request->rate_per_email;
            $package->free_call_minute_monthly = $request->free_call_minute_monthly;
            $package->free_sms_monthly = $request->free_sms_monthly;
            $package->free_fax_monthly = $request->free_fax_monthly;
            $package->free_emails_monthly = $request->free_emails_monthly;
            $package->free_did_monthly = $request->free_did_monthly;

            $package->saveOrFail();
            return $this->successResponse("Packages request created", $package->toArray());
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["Unable to save " . $request->all()], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save the package request", [$exception->getMessage()], $exception);
        }
    }

    /**
     * @OA\Get(
     *     path="/package/{key}",
     *     summary="Get package details by key",
     *     tags={"Subscriptions"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         required=true,
     *         description="key of the package",
     *         @OA\Schema(type="string", format="uuid", example="d290f1ee-6c54-4b01-90e6-d701748f0851")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Package details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Package info"),
     *             @OA\Property(property="data", type="object", example={
     *                 "id": 1,
     *                 "key": "d290f1ee-6c54-4b01-90e6-d701748f0851",
     *                 "name": "Pro Package",
     *                 "description": "Full access to all features",
     *                 "is_active": 1,
     *                 "currency_code": "USD",
     *                 "base_rate_monthly_billed": 29.99
     *             })
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Package not found or invalid request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid request"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function show(string $key)
    {
        try {
            $package_details = Package::findOrFail($key);
            $data = $package_details->toArray();
            return $this->successResponse("Package info", $data);
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["No package with key $key"], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch package info", [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/package/{key}",
     *     summary="Update an existing package",
     *     tags={"Subscriptions"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         required=true,
     *         description="key of the package to update",
     *         @OA\Schema(type="string", example="d290f1ee-6c54-4b01-90e6-d701748f0851")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={
     *                 "name", "description", "is_active", "display_order", "applicable_for",
     *                 "show_on", "modules", "currency_code",
     *                 "base_rate_monthly_billed", "base_rate_quarterly_billed",
     *                 "base_rate_half_yearly_billed", "base_rate_yearly_billed",
     *                 "call_rate_per_minute", "rate_per_sms", "rate_per_did",
     *                 "rate_per_fax", "rate_per_email"
     *             },
     *             @OA\Property(property="name", type="string", example="Pro Package Updated"),
     *             @OA\Property(property="description", type="string", example="Updated description of the package"),
     *             @OA\Property(property="is_active", type="integer", example=1),
     *             @OA\Property(property="display_order", type="integer", example=2),
     *             @OA\Property(property="applicable_for", type="integer", example=3),
     *             @OA\Property(property="show_on", type="array", @OA\Items(type="string"), example={"web", "app"}),
     *             @OA\Property(property="modules", type="array", @OA\Items(type="string"), example={"support", "analytics"}),
     *             @OA\Property(property="currency_code", type="string", example="EUR"),
     *             @OA\Property(property="base_rate_monthly_billed", type="number", format="float", example=39.99),
     *             @OA\Property(property="base_rate_quarterly_billed", type="number", format="float", example=99.99),
     *             @OA\Property(property="base_rate_half_yearly_billed", type="number", format="float", example=189.99),
     *             @OA\Property(property="base_rate_yearly_billed", type="number", format="float", example=359.99),
     *             @OA\Property(property="call_rate_per_minute", type="number", format="float", example=0.06),
     *             @OA\Property(property="rate_per_sms", type="number", format="float", example=0.025),
     *             @OA\Property(property="rate_per_did", type="number", format="float", example=2.10),
     *             @OA\Property(property="rate_per_fax", type="number", format="float", example=0.015),
     *             @OA\Property(property="rate_per_email", type="number", format="float", example=0.007)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Package updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Package updated"),
     *             @OA\Property(property="data", type="object", example={
     *                 "id": 1,
     *                 "key": "d290f1ee-6c54-4b01-90e6-d701748f0851",
     *                 "name": "Pro Package Updated",
     *                 "description": "Updated description of the package",
     *                 "currency_code": "EUR"
     *             })
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation failed or package not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid request"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function update(Request $request, string $key)
    {
        $this->validate($request, [
            "name" => "required|string",
            "description" => "required|string",
            "is_active" => "required|int",
            "display_order" => "required|int",
            "applicable_for" => "required|int",
            "show_on" => "required|array",
            "modules" => "required|array",
            "currency_code" => "required|string",
            "base_rate_monthly_billed" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "base_rate_quarterly_billed" => "required|regex:/^\d*(\.\d{1,6})?$/",
            "base_rate_half_yearly_billed" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "base_rate_yearly_billed" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "call_rate_per_minute" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_sms" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_did" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_fax" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_email" => "required|regex:/^\d*(\.\d{1,5})?$/",
        ]);
        $input = $request->all();
        try {
            $package_details = Package::findOrFail($key);
            $package_details->update($input);
            $data = $package_details->toArray();
            return $this->successResponse("Package updated", $data);
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["No package with key $key"], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update package", [], $exception);
        }
    }

    /**
     * Save initial data
     */
    public function saveInitialData(Request $request)
    {
        $data = [];
        try {
            $prospectInitialData = new ProspectInitialData();
            $prospectInitialData->email = $data['email'] = isset($request->email) ? $request->email : "";
            $prospectInitialData->name = $data['name'] = isset($request->name) ? $request->name : "";
            $prospectInitialData->company_name = $data['company_name'] = isset($request->company_name) ? $request->company_name : "";
            $prospectInitialData->country_code = $data['country_code'] = isset($request->country_code) ? $request->country_code : "";
            $prospectInitialData->phone_number = $data['phone_number'] = isset($request->phone_number) ? $request->phone_number : "";
            $prospectInitialData->save();

            #Send SMS Notifcation
            $setting = config("otp.sms");
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            $message = $data['name'] . "," . $data['country_code'] . "-" . $data['phone_number'] . ", " . $data['email'] . " from " . $data['company_name'] . " New Registration on website";
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

            $this->data['action'] = 'New user Notification-' . $data['phone_number'];
            $this->data["userInfo"]["name"] = $data['name'];
            $this->data["userInfo"]["phone_number"] = $data['country_code'] . '-' . $data['phone_number'];
            $this->data["userInfo"]["email"] = $data['email'];
            $this->data["userInfo"]["company_name"] = $data['company_name'];
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

            Log::debug("SendNotificationOnSavingProspedctInitialData.sendEmail.responseEmail", [$responseEmail]);

            return $this->successResponse("Prospect initial data saved", [$prospectInitialData]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Error in saving Prospect initial data" . $exception->getMessage(), [], $exception);
        }
    }
}
