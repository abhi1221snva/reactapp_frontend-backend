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
use App\Model\Client\SmsProviders;

use Plivo\RestClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use DB;
use App\Model\Client\Did;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Exceptions\TwilioException;
use App\Services\LoginOtpService;
use App\Services\AuthAuditService;

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

            // --- Per-email brute force protection ---
            $email = strtolower(trim($this->request->input('email', '')));
            $bruteForceKey = 'login_fail:' . md5($email);
            $failCount = (int) Cache::get($bruteForceKey, 0);
            if ($failCount >= 10) {
                $redis = Cache::getStore()->connection();
                $ttlSeconds = $redis->ttl(config('cache.prefix', 'laravel_cache') . ':' . $bruteForceKey);
                $remainingMinutes = max(1, (int) ceil(max($ttlSeconds, 0) / 60));
                Log::warning('Login blocked: brute force lockout', [
                    'email' => $email,
                    'ip'    => $clientIp,
                    'attempts' => $failCount,
                ]);
                AuthAuditService::log(null, 'login.locked', [
                    'email'    => $email,
                    'attempts' => $failCount,
                ], $clientIp);
                return response()->json([
                    'success' => false,
                    'message' => "Account temporarily locked due to too many failed attempts. Try again in {$remainingMinutes} minute(s).",
                ], 429);
            }

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
                    throw new RenderableException('Your organization account has been deactivated. Please contact support.', [], 403);
                }


                if ($device == 'mobile_app') {
                    $app_status = $data['app_status'];
                    if ($app_status == 0) {
                        throw new RenderableException('Mobile app access is not enabled for your account.', [], 403);
                    }
                }

                if ($data['ip_filtering'] == 1) {
                    try {
                        $allowed_ip = AllowedIp::on("mysql_" . $data["parent_id"])->where('ip_address', $clientIp)->get()->all();
                        if (empty($allowed_ip)) {
                            throw new RenderableException('Access denied from your current IP address.', [], 403);
                        }
                    } catch (RenderableException $e) {
                        throw $e; // Re-throw the IP denied error
                    } catch (\Throwable $e) {
                        Log::warning('Login: IP filtering check failed for client ' . $data['parent_id'], [
                            'error' => $e->getMessage(),
                        ]);
                        // Allow login to proceed — don't block because of a bad client DB
                    }
                }

                //call google totp authenticator //
                if (!empty($data['is_2fa_google_enabled']) && $data['is_2fa_google_enabled'] == 1) {
                    return response()->json([
                        'status'  => true,
                        'message' => 'Two-factor authentication required.',
                        'data'    => [
                            'requires_2fa' => 'google_totp',
                            'user_id'      => $data['id'],
                        ],
                    ]);
                }

                //call google authenticator //
if (!empty($data['is_2fa_phone_enabled']) && $data['is_2fa_phone_enabled'] == 1) {
                    // Rate-limit: max 3 OTP requests per 5 minutes
                    if (LoginOtpService::isRateLimited($data['id'])) {
                        return response()->json([
                            'status'  => false,
                            'message' => 'Too many OTP requests. Please wait a few minutes before trying again.',
                        ], 429);
                    }

                    $phoneOrEmail = $data['country_code'] . $data['mobile'];
                    $otpRecord    = LoginOtpService::send((int) $data['id'], $phoneOrEmail);
                    LoginOtpService::incrementRateLimit((int) $data['id']);

                    // Keep backward-compatible otpId in response (UUID string from old flow
                    // is replaced by integer id — frontend only needs to send the code back)
                    $data["otpId"] = (string) $otpRecord->id;

                    if (app()->environment('local', 'testing')) {
                        // Expose OTP in response for dev/QA convenience
                        $data["otp_dev"] = $otpRecord->otp_code;
                    }
                }

                 //call when enable_2fa is active

if (!empty($data['enable_2fa']) && $data['enable_2fa'] == 1) {
                    if (empty($data['country_code']) || empty($data['mobile'])) {
                        throw new RenderableException('Two-factor verification is not set up for this account. Please contact your administrator.', [], 400);
                    }

                    // --- Rate limiting ---
                    if (LoginOtpService::isRateLimited((int) $data['id'])) {
                        return response()->json([
                            'status'  => false,
                            'message' => 'Too many OTP requests. Please wait a few minutes before trying again.',
                        ], 429);
                    }

                    $to = $data['country_code'] . $data['mobile'];

                    // --- Generate secure random OTP and persist it ---
                    $otpRecord = LoginOtpService::send((int) $data['id'], $to);
                    LoginOtpService::incrementRateLimit((int) $data['id']);

                    $otp_value = $otpRecord->otp_code;

                    // --- Dispatch SMS via configured platform ---
                    $data_array             = [];
                    $data_array['to']       = $to;
                    $data_array['text']     = "Your Verification OTP for " . env('SITE_NAME') . " is " . $otp_value;
                    $json_data_to_send      = json_encode($data_array);

                    if ($client->sms_plateform == 'plivo') {
                        $data_array['from'] = env('PLIVO_SMS_NUMBER');
                        $plivo_user = env('PLIVO_USER');
                        $plivo_pass = env('PLIVO_PASS');

                        try {
                            $plivoClient = new RestClient($plivo_user, $plivo_pass);
                            $result = $plivoClient->messages->create(
                                $data_array['from'],
                                [$data_array['to']],
                                $data_array['text']
                            );
                        } catch (\Throwable $smsEx) {
                            Log::warning('Plivo OTP SMS failed', [
                                'to' => $data_array['to'],
                                'error' => $smsEx->getMessage(),
                            ]);
                        }
                    } elseif ($client->sms_plateform == 'didforsale') {
                        $data_array['from'] = env('SMS_NUMBER');
                        $api      = config('sms.sms_api.value');
                        $access   = config('sms.sms_access.value');
                        $sms_url  = config('sms.sms_access_url.value');

                        $json_data_to_send = json_encode($data_array);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sms_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data_to_send);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            "Content-Type: application/json",
                            "Authorization: Basic " . base64_encode("$api:$access"),
                        ]);

                        $result = curl_exec($ch);
                        $res    = json_decode($result);
                    }

                    Log::info("sms otp", [
                        "smsTo"   => $data_array['to'],
                        "from"    => $data_array['from'] ?? null,
                        "message" => $data_array['text'],
                    ]);

                    // --- Dispatch email OTP as well ---
                    try {
                        \App\Services\SystemMailerService::send('login-otp', $data['email'], [
                            'userName' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: $data['email'],
                            'otpCode'  => (string) $otp_value,
                        ]);

                        Log::info("email otp sent via SystemMailerService", ["to" => $data['email']]);
                    } catch (\Throwable $mailEx) {
                        Log::warning('OTP email failed', [
                            'to' => $data['email'],
                            'error' => $mailEx->getMessage(),
                        ]);
                    }

                    // Expose OTP record id (integer) in the response — backward-compatible
                    $data["otpId"] = (string) $otpRecord->id;

                    if (app()->environment('local', 'testing')) {
                        $data["otp_dev"] = $otp_value;
                    }
                }

                //close enable 2fa

                if ($data['webphone'] == 1) {
                    $webphone = User::where('id', $data['id'])->update(['webphone' => false]);
                    if ($webphone) {
                        Cache::put("user.webphone.{$data['id']}.{$data['parent_id']}", 0);
                        $data['webphone'] = 0;
                    }
                }

                // Organization-level 2FA enforcement: if client requires 2FA but user hasn't set it up
                if (empty($data['is_2fa_google_enabled']) || $data['is_2fa_google_enabled'] != 1) {
                    $enforcementClient = Client::find($data['base_parent_id']);
                    if ($enforcementClient && !empty($enforcementClient->require_2fa)) {
                        return response()->json([
                            'status'  => true,
                            'message' => 'Your organization requires two-factor authentication. Please set it up.',
                            'data'    => [
                                'requires_2fa_setup' => true,
                                'user_id'            => $data['id'],
                                'token'              => $data['token'],
                                'expires_at'         => $data['expires_at'],
                            ],
                        ]);
                    }
                }
            }


            $client = Client::findOrFail($data['base_parent_id']);
            if ($client->is_deleted == 1) {
                throw new RenderableException('Your organization account has been deactivated. Please contact support.', [], 403);
            }


            $objUserExtension = UserExtension::where("username", $data['alt_extension'])->first();
            $data['secret'] = $objUserExtension->secret ?? null;

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
                $clientPerm = $data["permissions"][$data["parent_id"]] ?? [];
                $response = [
                    "id" => $data["id"],
                    "parent_id" => $data["parent_id"],
                    "first_name" => $data["first_name"],
                    "last_name" => $data["last_name"],
                    "mobile" => $data["mobile"],
                    "email" => $data["email"],
                    "role" => $clientPerm["roleName"] ?? $data["role"],
                    "level" => $clientPerm["roleLevel"] ?? $data["level"],
                    "companyName" => $clientPerm["companyName"] ?? "",
                    "companyLogo" => $clientPerm["companyLogo"] ?? "",
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
                    "secret" => $objUserExtension ? base64_encode(convert_uuencode($objUserExtension->secret)) : null,
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

            // Create session record for device tracking
            $parsedUA = self::parseUserAgent($userAgent);
            \App\Model\Master\UserSession::create([
                'user_id'        => $data['id'],
                'token_hash'     => hash('sha256', $data['token']),
                'device_type'    => $parsedUA['device_type'],
                'browser'        => $parsedUA['browser'],
                'os'             => $parsedUA['os'],
                'ip_address'     => $clientIp,
                'last_active_at' => now(),
            ]);

            // Clear brute force counter on successful login
            Cache::forget($bruteForceKey);

            // Generate refresh token for token rotation
            $refreshData = \App\Http\Helper\JwtToken::createRefreshToken(
                (int) $data['id'],
                $clientIp,
                $userAgent
            );
            if (is_array($response)) {
                $response['refresh_token']            = $refreshData[0];
                $response['refresh_token_expires_at'] = $refreshData[1];
            }

            AuthAuditService::log($data['id'], 'login.success', [
                'device' => $device,
            ], $clientIp, $userAgent);

            return $this->successResponse("Login successful", $response);
        } catch (\Throwable $exception) {
            // Increment per-email brute force counter on auth failure (401)
            if (in_array($exception->getCode(), [401, 0])) {
                if (Cache::has($bruteForceKey)) {
                    Cache::increment($bruteForceKey);
                } else {
                    Cache::put($bruteForceKey, 1, 900); // 15 min TTL
                }
                $currentCount = (int) Cache::get($bruteForceKey, 1);
                Log::warning('Login failed', [
                    'email'    => $email,
                    'ip'       => $clientIp ?? $this->request->ip(),
                    'attempts' => $currentCount,
                ]);

                AuthAuditService::log(null, $currentCount >= 10 ? 'login.locked' : 'login.failed', [
                    'email'    => $email,
                    'attempts' => $currentCount,
                ], $clientIp ?? $this->request->ip());
            }
            return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
        }
    }



 

public function checkEmail(Request $request)
{
    try {

        // 1️⃣ Validate App Key
        $appKey = $request->header('X-Easify-App-Key');
        if ($appKey !== env('EASIFY_APP_KEY')) {
            throw new \Exception('Invalid or missing X-Easify-App-Key', 401);
        }

        // 2️⃣ Validate email format
        $this->validate($request, [
            'email' => 'required|email',
        ]);

        // 3️⃣ Check availability
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

    } catch (ValidationException $e) {

        return response()->json([
            'message' => 'Invalid email format',
            'errors' => $e->errors()
        ], 422);

    } catch (\Throwable $e) {

        $statusCode = in_array($e->getCode(), [401, 404, 422])
            ? $e->getCode()
            : 500;

        return response()->json([
            'message' => $e->getMessage(),
            'errors' => []
        ], $statusCode);
    }
}
   



   public function loginV2(Authentication $authentication)
{
    
    try {
        $this->validate($this->request, [
            'device'   => 'required' // mobile_app, desktop_app
        ]);

        $appKey = $this->request->header('X-Easify-App-Key');
        if ($appKey !== env('EASIFY_APP_KEY')) {
            throw new \Exception('Invalid or missing X-Easify-App-Key', 401);
        }
        // 🔑 Read token from header
        // $easifyToken = $this->request->header('X-Easify-User-Token');
        $easifyToken = trim($this->request->header('X-Easify-User-Token'));

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
$device = $this->request->input('device');

        // 🔐 Use same response-building logic
        // Reuse existing authentication service
        $data = $authentication->loginByUserId($user->id);

        if (empty($data) || !is_array($data)) {
            throw new RenderableException('Login failed', [], 401);
        }
if ($device === 'mobile_app') {
    if (empty($data['app_status']) || $data['app_status'] == 0) {
        throw new RenderableException(
            'Unauthorised For Mobile App Access',
            [],
            401
        );
    }
}

        // ---------- SAME CHECKS AS authentication() ----------
        $clientIp = $this->request->ip();

        if ($data['ip_filtering'] == 1) {
            try {
                $allowed_ip = AllowedIp::on("mysql_" . $data["parent_id"])
                    ->where('ip_address', $clientIp)
                    ->exists();
                if (!$allowed_ip) {
                    throw new RenderableException('Unauthorised IP address', [], 401);
                }
            } catch (RenderableException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Loginv2: IP filtering check failed for client ' . $data['parent_id'], [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $client = Client::find($data['base_parent_id']);
        if (!$client) {
            throw new RenderableException('Client not found', [], 404);
        }

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
        $clientPerm = $data["permissions"][$data["parent_id"]] ?? [];
        $response = [
            "id" => $data["id"],
            "parent_id" => $data["parent_id"],
            "first_name" => $data["first_name"],
            "last_name" => $data["last_name"],
            "mobile" => $data["mobile"],
            "email" => $data["email"],
            "role" => $clientPerm["roleName"] ?? $data["role"],
            "level" => $clientPerm["roleLevel"] ?? $data["level"],
            "companyName" => $clientPerm["companyName"] ?? "",
            "companyLogo" => $clientPerm["companyLogo"] ?? "",
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
            "secret" => $objUserExtension ? base64_encode(convert_uuencode($objUserExtension->secret)) : null,
        ];

        // ---------- LOGIN LOG ----------
        $log = new LoginLog();
        $log->user_id = $data["id"];
        $log->client_id = $data["parent_id"];
        $log->ip = $clientIp;
        $log->user_agent = $this->request->userAgent();
        $log->save();

        return $this->successResponse("Login successful", $response);

    }catch (\Throwable $exception) {

    $statusCode = in_array($exception->getCode(), [400, 401, 404, 422])
        ? $exception->getCode()
        : 500;

    return $this->failResponse(
        $exception->getMessage(),
        [],
        $exception,
        $statusCode
    );
    }
}


public function createUser(Request $request)
{
    try {

        // 1️⃣ Validate payload
        $this->validate($request, [
            'email'        => 'required|email',
            'name'         => 'required|string',
            'country_code' => ['required', 'regex:/^\+\d{1,4}$/'],
            'phone_number' => ['required', 'regex:/^\d{6,15}$/'],
            'password'     => ['required', 'string', 'min:10', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/'],
        ], [
            'password.min'   => 'Password must be at least 10 characters.',
            'password.regex' => 'Password must include uppercase, lowercase, a number, and a special character.',
        ]);

        // 2️⃣ Validate headers
        $easifyUserToken = $request->header('X-Easify-User-Token');
        if (!$easifyUserToken) {
            throw new \Exception('Missing X-Easify-User-Token', 401);
        }

        $appKey = $request->header('X-Easify-App-Key');
        if ($appKey !== env('EASIFY_APP_KEY')) {
            throw new \Exception('Invalid or missing X-Easify-App-Key', 401);
        }
        // ✅ ADD THIS BLOCK HERE
        if (User::where('easify_user_uuid', $easifyUserToken)->exists()) {
            throw ValidationException::withMessages([
                'easify_user_uuid' => ['This X-Easify-User-Token is already registered.']
            ]);
        }
        // 3️⃣ Email uniqueness (validation error → 422)
        if (User::where('email', $request->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.']
            ]);
        }

        // 4️⃣ Find reserved client
        $availableClient = Client::where('reserved', 1)
            ->where('client_type', 'phonify')
            ->first();

        if (!$availableClient) {
            throw new \Exception('No available client found', 404);
        }

        // 5️⃣ Fetch base user
        $user = User::where('parent_id', $availableClient->id)->first();
        if (!$user) {
            throw new \Exception('Base user not found', 404);
        }

        // 6️⃣ Update user details
        $nameParts = preg_split('/\s+/', trim($request->name), 2);

        $user->email            = $request->email;
        $user->first_name       = $nameParts[0];
        $user->last_name        = $nameParts[1] ?? '';
        $user->country_code     = ltrim($request->country_code, '+'); // store without +
        $user->mobile           = $request->phone_number;
        $user->password         = Hash::make($request->password);
        $user->easify_user_uuid = $easifyUserToken;
        $user->timezone = $request->timezone ?? APP_DEFAULT_USER_TIMEZONE;
        $user->is_deleted = 0;
        $user->status = 1; // if you have status column

        $user->save();

        // 7️⃣ Update SIP extensions
        UserExtension::whereIn('username', [
            $user->extension,
            $user->alt_extension,
            $user->app_extension
        ])->update([
            'secret' => $request->password
        ]);

        // 8️⃣ Mark client as consumed
        $availableClient->reserved = 0;
        $availableClient->save();

        // 9️⃣ Return SAME response as /authenticate
        $authService = new Authentication();
        $authData = $authService->loginByUserId($user->id);

        if (empty($authData) || !is_array($authData)) {
            throw new \Exception('Registration failed', 500);
        }

        return response()->json([
            "message" => "User registered successfully",
            "data" => $authData
        ], 200);

    } catch (ValidationException $e) {

        return response()->json([
            "message" => "Validation failed",
            "errors" => $e->errors()
        ], 422);

    } catch (\Throwable $e) {

        $statusCode = in_array($e->getCode(), [400, 401, 404, 422])
            ? $e->getCode()
            : 500;

        return response()->json([
            "message" => $e->getMessage(),
            "errors" => []
        ], $statusCode);
    }
}


public function createCredential(Request $request)
{
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

    $user_uuid = $request->header('X-Easify-User-Token');
    $parent_id = User::where('easify_user_uuid', $user_uuid)->value('parent_id');

    if (!$parent_id) {
        return response()->json([
            'message' => 'Invalid user'
        ], 401);
    }

    $connection = "mysql_" . $parent_id;

    $alreadyExists = SmsProviders::on($connection)
        ->where('uuid', $request->uuid)
        ->exists();

    if ($alreadyExists) {
        return response()->json([
            'message' => 'Credential already exists',
            'errors'  => [
                'uuid' => ['This credential UUID already exists']
            ]
        ], 422);
    }

    try {

        $credential = SmsProviders::on($connection)->create([
            'uuid'         => $request->uuid,
            'provider'     => $request->provider,
            'type'         => $request->type,
            'label_name'   => $request->credentials['account_name'],
            'auth_id'      => $request->credentials['account_sid'],
            'api_key'      => $request->credentials['auth_token'],
            'status'       => 1,
            


        ]);

        $trunkSid = null; // 👈 important

        // ================================
        // 🔥 TWILIO TRUNK CREATE
        // ================================
        if (strtolower(trim($credential->provider)) === 'twilio') {

            $accountSid = $credential->auth_id;
            $authToken  = $credential->api_key;

            if ($accountSid && $authToken && empty($credential->twilio_trunk_id)) {

                try {

                    $sipUrl = env('TWILIO_SIP_URL');

                    if (!$sipUrl) {
                        throw new \Exception('TWILIO_SIP_URL not configured');
                    }

                    $twilioClient = new TwilioClient($accountSid, $authToken);

                    $friendlyName = 'phonify-demo-crm-iocod-'
                        . $credential->id . '-'
                        . Carbon::now()->timestamp;

                    $trunk = $twilioClient->trunking
                        ->v1
                        ->trunks
                        ->create([
                            'friendlyName' => $friendlyName
                        ]);

                    $twilioClient->trunking
                        ->v1
                        ->trunks($trunk->sid)
                        ->originationUrls
                        ->create(
                            10,
                            10,
                            true,
                            $friendlyName,
                            $sipUrl
                        );

                    // Save to DB
                    $credential->update([
                        'twilio_trunk_id' => $trunk->sid,
                        'twilio_friendly_name' =>$trunk->friendlyName
                    ]);

                    $trunkSid = $trunk->sid; // 👈 store for response

                } catch (TwilioException $e) {

                    Log::error('Twilio trunk creation failed', [
                        'provider_id' => $credential->id,
                        'error'       => $e->getMessage()
                    ]);
                }
            } else {
                $trunkSid = $credential->twilio_trunk_id;
            }
        }

        // ================================

        return response()->json([
            'message' => 'Credential created successfully',
            'data' => [
                'uuid'            => $credential->uuid,
                'provider'        => $credential->provider,
                'type'            => $credential->type,
                'twilio_trunk_id'=> $trunkSid,   // 👈 trunk id in response
                'created_at'      => $credential->created_at->toIso8601String(),
            ]
        ], 200);

    } catch (QueryException $e) {

        if ($e->getCode() === '23000') {
            return response()->json([
                'message' => 'Credential already exists',
                'errors'  => [
                    'uuid' => ['Duplicate credential UUID']
                ]
            ], 422);
        }

        throw $e;
    }
}




public function createCredentialold(Request $request)
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

    // 2️⃣ Get user & DB
    $user_uuid = $request->header('X-Easify-User-Token');
    $parent_id = User::where('easify_user_uuid', $user_uuid)->value('parent_id');

    if (!$parent_id) {
        return response()->json([
            'message' => 'Invalid user'
        ], 401);
    }

    $connection = "mysql_" . $parent_id;

    // ✅ 3️⃣ CHECK DUPLICATE UUID (IMPORTANT)
    $alreadyExists = SmsProviders::on($connection)
        ->where('uuid', $request->uuid)
        ->exists();

    if ($alreadyExists) {
        return response()->json([
            'message' => 'Credential already exists',
            'errors'  => [
                'uuid' => ['This credential UUID already exists']
            ]
        ], 422); // Conflict
    }

    try {
        // 4️⃣ Create credential
        $credential = SmsProviders::on($connection)->create([
            'uuid'         => $request->uuid,
            'provider'     => $request->provider,
            'type'         => $request->type,
            'label_name'   => $request->credentials['account_name'],
            'auth_id'      => $request->credentials['account_sid'],
            'access_token' => $request->credentials['auth_token'],
            'status'       => 1,
        ]);

        return response()->json([
            'message' => 'Credential created successfully',
            'data' => [
                'uuid'       => $credential->uuid,
                'provider'   => $credential->provider,
                'type'       => $credential->type,
                'created_at' => $credential->created_at->toIso8601String(),
            ]
        ], 200);

    } catch (QueryException $e) {

        // ✅ 5️⃣ Handle duplicate key at DB level (fallback)
        if ($e->getCode() === '23000') {
            return response()->json([
                'message' => 'Credential already exists',
                'errors'  => [
                    'uuid' => ['Duplicate credential UUID']
                ]
            ], 422);
        }

        throw $e; // rethrow other DB errors
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
           $user_uuid  = $request->header('X-Easify-User-Token');
           $parent_id = User::where('easify_user_uuid', $user_uuid)->value('parent_id');
           if (!$parent_id) {
        return response()->json([
            'message' => 'Invalid user'
        ], 401);
    }

    // 3️⃣ Find credential (MASTER DB)
    $credential = SmsProviders::on("mysql_" . $parent_id)->where('uuid', $request->uuid)
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
public function deleteUser(Request $request)
{
    try {

        // 1️⃣ Validate headers
        $easifyUserToken = $request->header('X-Easify-User-Token');
        if (!$easifyUserToken) {
            throw new \Exception('Missing X-Easify-User-Token', 401);
        }

        // $appKey = $request->header('X-Easify-App-Key');
        // if ($appKey !== env('EASIFY_APP_KEY')) {
        //     throw new \Exception('Invalid or missing X-Easify-App-Key', 401);
        // }

        // 2️⃣ Find user by UUID
        $user = User::where('easify_user_uuid', $easifyUserToken)
            ->where('is_deleted', 0)
            ->first();

        if (!$user) {
            throw new \Exception('User not found', 404);
        }

        $parentId = $user->parent_id;

        DB::beginTransaction();

        // 3️⃣ Soft delete user
        $user->is_deleted = 1;
        $user->save();

        // 4️⃣ Release client in MASTER DB
        $client = Client::on('master')
            ->where('id', $parentId)
            ->first();

        if (!$client) {
            throw new \Exception('Client not found for user', 404);
        }

        $client->reserved = 1;
        $client->save();

        DB::commit();

        return response()->json([
            "message" => "User deleted successfully",
            "data" => [
                "user_id"   => $user->id,
                "parent_id" => $parentId
            ]
        ], 200);

    } catch (\Throwable $e) {

        DB::rollBack();

        $statusCode = in_array($e->getCode(), [400, 401, 404])
            ? $e->getCode()
            : 500;

        return response()->json([
            "message" => $e->getMessage(),
            "errors"  => []
        ], $statusCode);
    }
}



public function createPhoneNumber(Request $request)
{
    $validator = Validator::make($request->all(), [
        'uuid'             => 'required|uuid',
        'credential_uuid'  => 'required|uuid',
        'phone_number'     => 'required|string',
        'sid'              => 'required|string',
        'type'             => 'required|string',
        'active'           => 'required|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors(),
        ], 422);
    }

    // 1️⃣ Get user & DB connection
    $user_uuid = $request->header('X-Easify-User-Token');
    $parent_id = User::where('easify_user_uuid', $user_uuid)->value('parent_id');
    $user_id =User::where('easify_user_uuid', $user_uuid)->value('id');
    if (!$parent_id) {
        return response()->json([
            'message' => 'Invalid user'
        ], 401);
    }

    $connection = "mysql_" . $parent_id;

    // 2️⃣ Clean phone number
    $cleanPhone = preg_replace('/\D/', '', $request->phone_number);

    // 3️⃣ Save phone number in DB
    $phone = Did::on($connection)->updateOrCreate(
        ['uuid' => $request->uuid],
        [
            'credential_uuid'  => $request->credential_uuid,
            'cli'              => $cleanPhone,
            'phone_number_sid' => $request->sid,
            'type'             => $request->type,
            'status'           => $request->active,
            'dest_type'        => "1",
            'extension'        => $user_id,
            'default_did'     => "0",
            'set_exclusive_for_user'=> 0,
            'voip_provider'    =>"twilio"
        ]
    );

    // ======================================================
    // 🔥 TWILIO TRUNK ATTACH
    // ======================================================

    //if ($request->active) {

        try {

            $twilio = DB::connection($connection)
                ->table('sms_providers')
                ->where('provider', 'twilio')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->first();

            if ($twilio && !empty($twilio->twilio_trunk_id)) {

                $client = new TwilioClient($twilio->auth_id, $twilio->api_key);

                $trunkSid = $twilio->twilio_trunk_id;

                $client->trunking
                    ->v1
                    ->trunks($trunkSid)
                    ->phoneNumbers
                    ->create($request->sid);

                Log::info('Twilio SIP trunk updated successfully', [
                    'trunk_sid' => $trunkSid,
                    'phone_sid' => $request->sid
                ]);

            } else {

                Log::error('Twilio trunk not configured or missing trunk ID', [
                    'phone_sid' => $request->sid
                ]);
            }

        } catch (TwilioException $e) {

            // Only log — do not break main API response
            Log::error('Twilio SIP trunk update failed', [
                'error'     => $e->getMessage(),
                'phone_sid' => $request->sid
            ]);
        }
   // }

    // ======================================================

    return response()->json([
        'success' => true,
        'message' => 'Phone number saved successfully',
        'data'    => [
            'phonify_id' => $phone->uuid,
        ],
    ]);
}






    public function createPhoneNumberold(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'uuid'             => 'required|uuid',
            'credential_uuid'  => 'required|uuid',
            'phone_number'     => 'required|string',
            'sid'              => 'required|string',
            'type'             => 'required|string',
            'active'           => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }
  // 2️⃣ Get user & DB
    $user_uuid = $request->header('X-Easify-User-Token');
    $parent_id = User::where('easify_user_uuid', $user_uuid)->value('parent_id');

    if (!$parent_id) {
        return response()->json([
            'message' => 'Invalid user'
        ], 401);
    }
$phone = preg_replace('/\D/', '', $request->phone_number);

    $connection = "mysql_" . $parent_id;
        $phone = Did::on($connection)->updateOrCreate(
            ['uuid' => $request->uuid],
            [
                'credential_uuid' => $request->credential_uuid,
                'cli'          => $phone,
                'phone_number_sid'=> $request->sid,
                'type'            => $request->type,
                'status'          => $request->active,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Phone number saved successfully',
            'data'    => [
                'phonify_id' => $phone->uuid,
            ],
        ]);
    }

    /**
     * POST /phone-number/update
     */
    public function updatePhoneNumber(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'uuid'   => 'required|uuid',
            'active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }
  // 2️⃣ Get user & DB
    $user_uuid = $request->header('X-Easify-User-Token');
    $parent_id = User::where('easify_user_uuid', $user_uuid)->value('parent_id');

    if (!$parent_id) {
        return response()->json([
            'message' => 'Invalid user'
        ], 401);
    }

    $connection = "mysql_" . $parent_id;
        $phone = Did::on($connection)->where('uuid', $request->uuid)->first();

        if (!$phone) {
            return response()->json([
                'success' => false,
                'message' => 'Phone number not found',
                'errors'  => [],
            ], 404);
        }

        $phone->update([
            'status' => $request->active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Phone number updated successfully',
        ]);
    }

    /**
     * POST /phone-number/delete
     */
    public function deletePhoneNumber(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }
  // 2️⃣ Get user & DB
    $user_uuid = $request->header('X-Easify-User-Token');
    $parent_id = User::where('easify_user_uuid', $user_uuid)->value('parent_id');

    if (!$parent_id) {
        return response()->json([
            'message' => 'Invalid user'
        ], 401);
    }

    $connection = "mysql_" . $parent_id;
        $phone = Did::on($connection)->where('uuid', $request->uuid)->first();

        if (!$phone) {
            return response()->json([
                'success' => false,
                'message' => 'Phone number not found',
                'errors'  => [],
            ], 404);
        }
   // ======================================================
    // 🔥 REMOVE FROM TWILIO TRUNK
    // ======================================================

    try {

        $twilio = DB::connection($connection)
            ->table('sms_providers')
            ->where('provider', 'twilio')
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->first();

        if ($twilio && !empty($twilio->twilio_trunk_id) && !empty($phone->phone_number_sid)) {

            $client = new TwilioClient($twilio->auth_id, $twilio->api_key);

            $client->trunking
                ->v1
                ->trunks($twilio->twilio_trunk_id)
                ->phoneNumbers($phone->phone_number_sid)
                ->delete();

            Log::info('Twilio trunk phone number deleted successfully', [
                'trunk_sid' => $twilio->twilio_trunk_id,
                'phone_sid' => $phone->phone_number_sid
            ]);
        }

    } catch (TwilioException $e) {

        Log::error('Twilio trunk phone number delete failed', [
            'error'     => $e->getMessage(),
            'phone_sid' => $phone->phone_number_sid ?? null
        ]);

        // ⚠️ Optional: You can decide if you want to stop here
        // return response()->json([...], 500);
    }

    // ======================================================
    // 🗑 Delete from DB
    // ======================================================
        $phone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Phone number deleted successfully',
        ]);
    }
    /**
     * @OA\Post(
     *     path="/logout",
     *     summary="Revoke the current JWT token",
     *     tags={"Authentication"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logged out successfully"
     *     )
     * )
     *
     * Blacklists the bearer token in Redis so it cannot be reused.
     * The blacklist key auto-expires when the token's own exp would have fired.
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken() ?? $request->get('token');

        if ($token) {
            \App\Http\Helper\JwtToken::blacklist($token);
        }

        $userId = $request->auth->id ?? null;
        if ($userId) {
            \App\Http\Helper\JwtToken::revokeAllRefreshTokens((int) $userId);
        }
        AuthAuditService::log($userId, 'logout');

        return $this->successResponse('Logged out successfully.');
    }

    /**
     * POST /auth/refresh
     * Exchange a valid refresh token for a new access token + rotated refresh token.
     */
    public function refresh(Request $request)
    {
        $this->validate($request, [
            'refresh_token' => 'required|string',
        ]);

        $result = \App\Http\Helper\JwtToken::rotateRefreshToken(
            $request->input('refresh_token'),
            $request->ip(),
            $request->userAgent()
        );

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token. Please log in again.',
            ], 401);
        }

        [$userId, $newRefreshToken, $refreshExpiresAt] = $result;

        $user = User::find($userId);
        if (!$user || $user->is_deleted || ($user->status ?? 1) == 0) {
            return response()->json([
                'success' => false,
                'message' => 'Account not active.',
            ], 403);
        }

        $tokenData = \App\Http\Helper\JwtToken::createToken($userId);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed.',
            'data'    => [
                'token'                    => $tokenData[0],
                'expires_at'               => $tokenData[1],
                'refresh_token'            => $newRefreshToken,
                'refresh_token_expires_at' => $refreshExpiresAt,
            ],
        ]);
    }

    /**
     * Parse a User-Agent string into device type, browser, and OS.
     */
    private static function parseUserAgent(?string $ua): array
    {
        $ua = $ua ?? '';
        $device = 'desktop';
        if (preg_match('/Mobile|Android|iPhone/i', $ua)) $device = 'mobile';
        elseif (preg_match('/Tablet|iPad/i', $ua)) $device = 'tablet';

        $browser = 'Unknown';
        if (preg_match('/Edg(e|\/)/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/Chrome\//i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/Firefox\//i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) $browser = 'Safari';

        $os = 'Unknown';
        if (preg_match('/Windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Macintosh|Mac OS/i', $ua)) $os = 'macOS';
        elseif (preg_match('/iPhone|iPad/i', $ua)) $os = 'iOS';
        elseif (preg_match('/Android/i', $ua)) $os = 'Android';
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';

        return ['device_type' => $device, 'browser' => $browser, 'os' => $os];
    }
}
