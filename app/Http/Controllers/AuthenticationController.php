<?php

namespace App\Http\Controllers;

use App\Model\Client\FaxDid;
use App\Model\Client\AllowedIp;
use App\Model\Master\UserExtension;
use Illuminate\Http\Request;
use App\Model\Authentication;
use App\Model\Master\LoginLog;
use Illuminate\Support\Facades\Log;
use App\Model\Master\AsteriskServer;
use App\Model\User;
use Illuminate\Support\Facades\Cache;
use App\Exceptions\RenderableException;
use App\Services\SmsService;
use Illuminate\Support\Str;
use App\Model\Master\OtpVerification;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Services\MailService;

use App\Mail\SystemNotificationMail;
use App\Model\Master\Client;
use App\Model\Master\GatewayCredential;

use Plivo\RestClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;


class AuthenticationController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }


    /**
     * @OA\Post(
     *     path="/authentication",
     *     summary="Login and get auth token",
     *     tags={"Authentication"},
     *      @OA\Parameter(
     *          name="email",
     *          description="Email",
     *          required=true,
     *          in="query",
     *         @OA\Schema(
     *           type="string"
     *         )
     *      ),
     *      @OA\Parameter(
     *          name="password",
     *          description="Password",
     *          required=true,
     *          in="query",
     *         @OA\Schema(
     *           type="string"
     *         )
     *      ),
     *      @OA\Parameter(
     *          name="device",
     *          description="device",
     *          required=true,
     *          example="desktop_app",
     *          in="query",
     *         @OA\Schema(
     *           type="string"
     *         )
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Login successful"
     *      ),
     *      @OA\Response(
     *          response="401",
     *          description="Invalid email or password"
     *      )
     * )
     */


    public function authentication(Authentication $authentication)
    {
        $apiKey = $this->request->input('apiKey', null);
      // $easifyToken = $this->request->header('X-Easify-User-Token');
        if (!empty($apiKey)) {

            $this->validate($this->request, [
                'email' => 'required|email',
                'clientIp' => ["sometimes", "required", "ip"],
                'apiKey' => 'required',
            ]);
        } else {

            $this->validate($this->request, [
                'password' => 'required',
                'email' => 'required|email',
                'clientIp' => ["sometimes", "required", "ip"],
                'device'   => 'required' // mobile_app,desktop_app
            ]);
        }



        try {
            $device = $this->request->input('device', null);
            $clientIp = $this->request->input('clientIp', null);
            if (empty($clientIp)) $clientIp = $this->request->ip();

            //api key



            if (!empty($apiKey)) {
                //$data = $authentication->loginApiKey($this->request->input('email'), $this->request->input('apiKey'),$easifyToken);
                $data = $authentication->loginApiKey($this->request->input('email'), $this->request->input('apiKey'));

            }

            //close api key

            else {


                //$data = $authentication->login($this->request->input('email'), $this->request->input('password'),$easifyToken);
                $data = $authentication->login($this->request->input('email'), $this->request->input('password'));


                $client = Client::findOrFail($data['base_parent_id']);
                if ($client->is_deleted == 1) {
                    throw new RenderableException('Account de-activated', [], 401);
                }


                if ($device == 'mobile_app') {
                    $app_status = $data['app_status'];
                    if ($app_status == 0) {
                        throw new RenderableException('Unauthorised For Mobile App Access', [], 401);
                    }
                }

                if ($data['ip_filtering'] == 1) {
                    $allowed_ip = AllowedIp::on("mysql_" . $data["parent_id"])->where('ip_address', $clientIp)->get()->all();

                    if (!empty($allowed_ip)) {
                    } else {

                        throw new RenderableException('Unauthorised IP address', [], 401);
                    }
                }

                //call google authenticator //
if (!empty($data['is_2fa_phone_enabled']) && $data['is_2fa_phone_enabled'] == 1) {
                    //  $otp_value = mt_rand(100000, 999999);
                    $otp_value = 123456;
                    $otp = new OtpVerification();
                    $otp->id = Str::uuid()->toString();
                    $otp->user_id = $data['id'];
                    $otp->country_code = $data['country_code'];
                    $otp->phone_number = $data['mobile'];
                    $otp->code = $otp_value;
                    $otp->expiry = (new \DateTime())->modify("+15 minutes");
                    //$otp->status = 2;//self::REQUESTED;
                    $otp->saveOrFail();
                    //throw new RenderableException('Enable 2FA', [$res], 401);
                    $data["otpId"] = $otp->id;
                }

                //call when enable_2fa is active

if (!empty($data['enable_2fa']) && $data['enable_2fa'] == 1) {
                    if (empty($data['country_code'])) {
                        throw new RenderableException('Country Code not found for otp varification', [], 401);
                    }

                    if (empty($data['mobile'])) {
                        throw new RenderableException('Mobile Number not found for otp varification', [], 401);
                    }


                    $to = $data['country_code'] . $data['mobile'];

                    $data_array = array();
                    $data_array['to'] = $to;
                    $otp_value = mt_rand(100000, 999999);
                    $data_array['text'] = "Your Verification OTP for " . env('SITE_NAME') . " is " . $otp_value;
                    $json_data_to_send = json_encode($data_array);


                    if ($client->sms_plateform == 'plivo') {
                        $data_array['from'] = env('PLIVO_SMS_NUMBER');
                        $plivo_user = env('PLIVO_USER');
                        $plivo_pass = env('PLIVO_PASS');

                        $client = new RestClient($plivo_user, $plivo_pass);
                        $result = $client->messages->create([
                            "src" => $data_array['from'],
                            "dst" => $data_array['to'],
                            "text"  => $data_array['text'],
                            "url" => ""
                        ]);
                    } else
                    if ($client->sms_plateform == 'didforsale') {
                        $data_array['from'] = env('SMS_NUMBER');
                        $api = config('sms.sms_api.value');
                        $access = config('sms.sms_access.value');
                        $sms_url = config('sms.sms_access_url.value');

                        $json_data_to_send = json_encode($data_array);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sms_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data_to_send);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode("$api:$access")));

                        $result = curl_exec($ch);
                        $res = json_decode($result);
                    }

                    Log::info("sms otp", [
                        "result" => $result,
                        "smsTo" => $data_array['to'],
                        "from" => $data_array['from'],
                        "message" => $data_array['text']
                    ]);
                    //return $result;

                    $otp = new OtpVerification();
                    $otp->id = Str::uuid()->toString();
                    $otp->user_id = $data['id'];
                    $otp->country_code = $data['country_code'];
                    $otp->phone_number = $data['mobile'];
                    $otp->code = $otp_value;
                    $otp->expiry = (new \DateTime())->modify("+15 minutes");
                    //$otp->status = 2;//self::REQUESTED;
                    $otp->saveOrFail();
                    //throw new RenderableException('Enable 2FA', [$res], 401);

                    $data["otpId"] = $otp->id;

                    //email

                    $smtpSetting = new SmtpSetting;
                    $smtpSetting->mail_driver = "SMTP";
                    $smtpSetting->mail_host = env("PORTAL_MAIL_HOST");
                    $smtpSetting->mail_port = env("PORTAL_MAIL_PORT");
                    $smtpSetting->mail_username = env("PORTAL_MAIL_USERNAME");
                    $smtpSetting->mail_password = env("PORTAL_MAIL_PASSWORD");
                    $smtpSetting->from_name = env("PORTAL_MAIL_SENDER_NAME");
                    $smtpSetting->from_email = env("PORTAL_MAIL_SENDER_EMAIL");
                    $smtpSetting->mail_encryption = env("PORTAL_MAIL_ENCRYPTION");

                    /*$from = [
                        "address" => empty($smtpSetting->from_email) ? "support@domain.com" : $smtpSetting->from_email,
                        "name" => empty($smtpSetting->from_name) ? "Domain Notification" : $smtpSetting->from_name,
                    ];*/

                    $from = [
                        "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                        "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
                    ];

                    $data["action"] = 'Verification Code - ' . date('Y-m-d H:i:s');
                    $data['otp'] = $otp_value;
                    $mailable = new SystemNotificationMail($from, "emails.verificationCode", $data["action"], $data);

                    $mailService = new MailService($data['parent_id'], $mailable, $smtpSetting);
                    $emails = $mailService->sendEmail($data['email']);

                    Log::debug("SendOtpEmailVerification.sendEmailOtp.responseEmail", [$emails, $otp_value]);


                    Log::info("email otp", [
                        "result" => $emails,
                    ]);
                }

                //close enable 2fa

                if ($data['webphone'] == 1) {
                    $webphone = User::where('id', $data['id'])->update(['webphone' => false]);
                    if ($webphone) {
                        Cache::put("user.webphone.{$data['id']}.{$data['parent_id']}", 0);
                        $data['webphone'] = 0;
                    }
                }
            }


            $client = Client::findOrFail($data['base_parent_id']);
            if ($client->is_deleted == 1) {
                throw new RenderableException('Account de-activated', [], 401);
            }





            $objUserExtension = UserExtension::where("username", $data['alt_extension'])->first();
            $data['secret'] = $objUserExtension->secret;

            $server = AsteriskServer::find($data["asterisk_server_id"]);
            if (!empty($server->host)) {
                $data["server"] = $server->host;
                $data["domain"] = $server->domain;
            } else {
                $data["server"] = null;
                $data["domain"] = null;
            }

            $client = $this->request->header('x-client', null);
            if ($client === env("X_CLIENT")) {
                if ($server) {
                    try {
                        #whitelist the IP on the server
                        $data["fax_did"]  = FaxDid::on("mysql_" . $data["parent_id"])->where('userId', $data["id"])->select('did')->pluck('did')->first();

                        $data["whitelist"] = $server->requestIpWhitelist($clientIp, $data["id"], $data["parent_id"]);
                    } catch (\Throwable $exception) {
                        Log::warning("Authentication failed to requestIpWhitelist", [
                            "data" => $data,
                            "clientIp" => $clientIp,
                            "message" => $exception->getMessage(),
                            "file" => $exception->getFile(),
                            "line" => $exception->getLine(),
                            "code" => $exception->getCode()
                        ]);
                    }
                }
                $response = $data;
            } else {
                $response = [
                    "id" => $data["id"],
                    "parent_id" => $data["parent_id"],
                    "first_name" => $data["first_name"],
                    "last_name" => $data["last_name"],
                    "mobile" => $data["mobile"],
                    "email" => $data["email"],
                    "companyName" => $data["permissions"][$data["parent_id"]]["companyName"],
                    "companyLogo" => $data["permissions"][$data["parent_id"]]["companyLogo"],
                    "profile_pic" => $data["profile_pic"],
                    "extension" => $data["extension"],
                    "alt_extension" => $data["alt_extension"],
                    "app_extension" => $data["app_extension"],
                    "dialer_mode" => $data["dialer_mode"],
                    "token" => $data["token"],
                    "expires_at" => $data["expires_at"],
                    "server" => $data["server"],
                    "domain" => $data["domain"],
                    "did" => $data["did"],
                    "secret" => base64_encode(convert_uuencode($objUserExtension->secret))
                    
                ];
                if ($server) {
                    try {
                        #whitelist the IP on the server
                        $data["whitelist"] = $server->whiteListIp($clientIp, $data["id"], $data["parent_id"]);
                    } catch (\Throwable $exception) {
                        Log::warning("Authentication failed to whiteListIp", [
                            "data" => $data,
                            "clientIp" => $clientIp,
                            "message" => $exception->getMessage(),
                            "file" => $exception->getFile(),
                            "line" => $exception->getLine(),
                            "code" => $exception->getCode()
                        ]);
                    }
                }
            }

            $log = new LoginLog();
            $log->user_id = $data["id"];
            $log->client_id = $data["parent_id"];
            $log->ip = $clientIp;
            $userAgent = $this->request->input('userAgent', null);
            if (empty($userAgent)) $userAgent = $this->request->userAgent();
            $log->user_agent = $userAgent;
            $log->save();



            return $this->successResponse("Login successful", $response);
        } catch (\Throwable $exception) {
            return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
        }
    }



    public function checkEmailold(Request $request)
    {
        $this->validate($request, [
            'email' => 'required|email'
        ]);

        $exists = User::where('email', $request->email)->exists();

        return response()->json(['exists'  => $exists,'message' => $exists ? 'Email already exists' : 'Email is available' ]);
    }
public function checkEmail(Request $request)
{
       $appKey = $request->header('X-Easify-App-Key');

        if ($appKey !== env('EASIFY_APP_KEY')) {
            return response()->json([
                'message' => 'Invalid or missing X-Easify-App-Key'
            ], 401);
        }
    // Email format validation
      $this->validate($request, [
        'email'      => 'required|email',
    ]);

    // Check availability
    $exists = User::where('email', $request->email)->exists();

    if ($exists) {
        return response()->json([
            'message' => 'Email validation failed',
            'data' => [
                'is_valid' => false
            ]
        ], 200);
    }

    return response()->json([
        'message' => 'Email validation successful',
        'data' => [
            'is_valid' => true
        ]
    ], 200);
}

    public function createUserold(Request $request)
{
    // Step 1: Validate required fields
    $this->validate($request, [
        'email'      => 'required|email',
        'password'   => 'required|min:6',
    ]);

    // Step 2: Check if email already exists
    if (User::where('email', $request->email)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Email already exists in the system.'
        ], 409);
    }

    // Step 3: Find reserved voiptella client
    $availableClient = Client::where('reserved', 1)
        ->where('client_type', 'voiptella')
        ->first();
    
    if (!$availableClient) {
        return response()->json([
            'success' => false,
            'message' => 'No available client found. All voiptella clients are reserved.'
        ], 404);
    }

    // Step 4: Find user assigned to this client
    $existingUser = User::where('base_parent_id', $availableClient->id)->first();

    if (!$existingUser) {
        return response()->json([
            'success' => false,
            'message' => 'Client found but no associated user exists.'
        ], 404);
    }


    $phone = substr($request->phone, -10);

    $countryCode = substr($request->phone, 0, strlen($request->phone) - 10);

    // Step 5: Update user details
    $existingUser->email      = $request->email;
    //$existingUser->first_name = $request->first_name;
    //$existingUser->last_name  = $request->last_name;
    $existingUser->mobile  = $phone;
    $existingUser->country_code  = $countryCode;


    $existingUser->password   = Hash::make($request->password);
    $existingUser->save();

    // Step 6: Update SIP passwords in user_extensions
    $extensionsToUpdate = [
        $existingUser->extension,
        $existingUser->alt_extension,
        $existingUser->app_extension
    ];

    UserExtension::whereIn('username', $extensionsToUpdate)
        ->update(['secret' => $request->password]);

    // Step 7: Mark client as reserved = 0 (now consumed)
    $availableClient->reserved = 0;
    $availableClient->save();

   $authResponse = Http::post(env('APP_URL') . '/authentication', [
    'email'    => $existingUser->email,
    'password' => $request->password,
    'device'   => 'desktop_app'
]);

return  $authData = $authResponse->json();

    // Step 9: Return success response with authentication details
    return response()->json([
        'success' => true,
        'message' => 'User created and authenticated successfully.',
        'client_id'    => $availableClient->id,
        'company_name' => $availableClient->company_name,
        'user' => [
            'id'         => $existingUser->id,
            'email'      => $existingUser->email,
            'first_name' => $existingUser->first_name,
            'last_name'  => $existingUser->last_name
        ],
        'auth' => $authResponse   // token + login response
    ]);
}




   public function loginV2(Authentication $authentication)
{
    try {

        // 🔑 Read token from header
        $easifyToken = $this->request->header('X-Easify-User-Token');

        if (empty($easifyToken)) {
            throw new RenderableException('X-Easify-User-Token missing', [], 401);
        }

        // 🔍 Find user by token
        $user = User::where('easify_user_uuid', $easifyToken)->first();

        if (!$user) {
            throw new RenderableException('Invalid token', [], 401);
        }

        if ($user->is_deleted == 1) {
            throw new RenderableException('Account de-activated', [], 401);
        }

        // 🔐 Use same response-building logic
        // Reuse existing authentication service
        $data = $authentication->loginByUserId($user->id);
if (empty($data) || !is_array($data)) {
    throw new RenderableException('Login failed', [], 401);
}

        // ---------- SAME CHECKS AS authentication() ----------
        $clientIp = $this->request->ip();

        if ($data['ip_filtering'] == 1) {
            $allowed_ip = AllowedIp::on("mysql_" . $data["parent_id"])
                ->where('ip_address', $clientIp)
                ->exists();

            if (!$allowed_ip) {
                throw new RenderableException('Unauthorised IP address', [], 401);
            }
        }

        $client = Client::findOrFail($data['base_parent_id']);
        if ($client->is_deleted == 1) {
            throw new RenderableException('Account de-activated', [], 401);
        }

        // ---------- EXTENSION / SERVER ----------
        $objUserExtension = UserExtension::where("username", $data['alt_extension'])->first();
        $data['secret'] = $objUserExtension->secret ?? null;

        $server = AsteriskServer::find($data["asterisk_server_id"]);
        $data["server"] = $server->host ?? null;
        $data["domain"] = $server->domain ?? null;

        // ---------- RESPONSE (same as authentication) ----------
        $response = [
            "id" => $data["id"],
            "parent_id" => $data["parent_id"],
            "first_name" => $data["first_name"],
            "last_name" => $data["last_name"],
            "mobile" => $data["mobile"],
            "email" => $data["email"],
            "companyName" => $data["permissions"][$data["parent_id"]]["companyName"],
            "companyLogo" => $data["permissions"][$data["parent_id"]]["companyLogo"],
            "profile_pic" => $data["profile_pic"],
            "extension" => $data["extension"],
            "alt_extension" => $data["alt_extension"],
            "app_extension" => $data["app_extension"],
            "dialer_mode" => $data["dialer_mode"],
            "token" => $data["token"],
            "expires_at" => $data["expires_at"],
            "server" => $data["server"],
            "domain" => $data["domain"],
            "did" => $data["did"],
            "secret" => base64_encode(convert_uuencode($objUserExtension->secret))
        ];

        // ---------- LOGIN LOG ----------
        $log = new LoginLog();
        $log->user_id = $data["id"];
        $log->client_id = $data["parent_id"];
        $log->ip = $clientIp;
        $log->user_agent = $this->request->userAgent();
        $log->save();

        return $this->successResponse("Login successful", $response);

    } catch (\Throwable $exception) {
        return $this->failResponse(
            $exception->getMessage(),
            [],
            $exception,
            $exception->getCode() ?: 401
        );
    }
}

public function createUser(Request $request)
{
    // 1️⃣ Validate payload
    $this->validate($request, [
        'email'        => 'required|email',
        'name'         => 'required|string',
        'country_code' => ['required', 'regex:/^\+\d{1,4}$/'],
        'phone_number' => ['required', 'regex:/^\d{6,15}$/'],
        'password'     => 'required|min:6',
    ]);

    // 2️⃣ Read Easify User Token from header
    $easifyUserToken = $request->header('X-Easify-User-Token');

    if (!$easifyUserToken) {
        return response()->json([
            'success' => false,
            'message' => 'Missing X-Easify-User-Token header'
        ], 401);
    }
   $appKey = $request->header('X-Easify-App-Key');

        if ($appKey !== env('EASIFY_APP_KEY')) {
            return response()->json([
                'message' => 'Invalid or missing X-Easify-App-Key'
            ], 401);
        }
    // 3️⃣ Check email uniqueness
    if (User::where('email', $request->email)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Email already exists'
        ], 409);
    }

    // 4️⃣ Find reserved client
    $availableClient = Client::where('reserved', 1)
        ->where('client_type', 'voiptella')
        ->first();
        Log::info('client avaiable',['availableClient'=>$availableClient]);
    if (!$availableClient) {
        return response()->json([
            'success' => false,
            'message' => 'No available client found'
        ], 404);
    }

    // 5️⃣ Fetch base user
    $user = User::where('base_parent_id', $availableClient->id)->first();
    if(!$user){
    Log::warning('No user found for this client', ['client_id' => $availableClient->id]);
}
// 6️⃣ Update user details
$nameParts = preg_split('/\s+/', trim($request->name), 2);
    // 6️⃣ Update user details
    $user->email            = $request->email;
    $user->first_name       = $nameParts[0];
    $user->last_name        = $nameParts[1] ?? '';
    $user->country_code     = ltrim($request->country_code, '+'); // ✅ strip +
    $user->mobile           = $request->phone_number;
    $user->password         = Hash::make($request->password);
    $user->easify_user_uuid = $easifyUserToken; // ✅ REQUIRED
    $user->save();

    // 7️⃣ Update SIP extensions
    UserExtension::whereIn('username', [
        $user->extension,
        $user->alt_extension,
        $user->app_extension
    ])->update([
        'secret' => $request->password
    ]);

    // 8️⃣ Mark client consumed
    $availableClient->reserved = 0;
    $availableClient->save();

    // 9️⃣ Return SAME response as login/authenticate
    $authService = new Authentication();
    $authData = $authService->loginByUserId($user->id);

    return response()->json($authData);
}
public function createCredential(Request $request)
{
    // 1️⃣ Validate request payload
    $validator = Validator::make($request->all(), [
        'uuid'                       => 'required|string',
        'provider'                   => 'required|string',
        'type'                       => 'required|string',
        'credentials'                => 'required|array',
        'credentials.account_name'   => 'required|string',
        'credentials.account_sid'    => 'required|string',
        'credentials.auth_token'     => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Failed to create credential',
            'errors'  => $validator->errors()
        ], 422);
    }

    try {
        // 2️⃣ Create credential record
        $credential = GatewayCredential::create([
            'uuid'        => $request->uuid,
            'user_uuid'  => $request->header('X-Easify-User-Token'),
            'provider'    => $request->provider,
            'type'        => $request->type,
            'credentials' => json_encode($request->credentials),
        ]);

        // 3️⃣ Success response
        return response()->json([
            'message' => 'Credential created successfully',
            'data' => [
                'uuid'       => $credential->uuid,
                'provider'   => $credential->provider,
                'type'       => $credential->type,
                'created_at' => $credential->created_at->toIso8601String(),
            ]
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to create credential',
            'errors'  => [
                'exception' => [$e->getMessage()]
            ]
        ], 500);
    }
}
public function deleteCredential(Request $request)
{
    // 1️⃣ Validate payload
    $validator = Validator::make($request->all(), [
        'uuid' => 'required|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Failed to delete credential',
            'errors'  => $validator->errors()
        ], 422);
    }

    // 2️⃣ Identify user
    $userUuid = $request->header('X-Easify-User-Token');

    // 3️⃣ Find credential (MASTER DB)
    $credential = GatewayCredential::where('uuid', $request->uuid)
        ->where('user_uuid', $userUuid)
        ->first();

    if (! $credential) {
        return response()->json([
            'message' => 'Failed to delete credential',
            'errors'  => [
                'uuid' => ['The credential was not found.']
            ]
        ], 404);
    }

    // 4️⃣ Soft delete
    $credential->delete();

    // 5️⃣ Success response
    return response()->json([
        'message' => 'Credential deleted successfully',
        'data' => [
            'uuid'       => $credential->uuid,
            'deleted_at' => Carbon::now()->toIso8601String()
        ]
    ], 200);
}
}
