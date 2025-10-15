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

use Plivo\RestClient;




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
                $data = $authentication->loginApiKey($this->request->input('email'), $this->request->input('apiKey'));
            }

            //close api key

            else {


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
                    "client_id" => $data["parent_id"],
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

}
