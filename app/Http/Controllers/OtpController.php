<?php

namespace App\Http\Controllers;

use App\Services\OtpService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Model\User;
use App\Model\Master\WebLeads;
use App\Model\Master\WebEmailVerification;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\SystemNotificationMail;

use App\Model\Client\SmtpSetting;

use App\Model\Client\SystemNotification;


use App\Services\MailService;
use Twilio\Rest\Client;
use DB;




class OtpController extends Controller
{
    const REQUESTED = 1;
    const PROCESSING = 2;
    const SENT = 3;
    const VERIFIED = 4;
    const FAILED = 5;
    const INVALID = 6;

    public function validateCompany(Request $request)
    {
        $this->validate($request, [
            "company" => "required|unique:clients,company_name|unique:prospects,company_name"
        ]);

        return $this->successResponse("Company validate", []);
    }

    public function validateEmail(Request $request)
    {
        $this->validate($request, [
            "email" => "required|email|unique:users,email|unique:prospects,email"
        ]);

        return $this->successResponse("Email validate", []);
    }

    public function validatePhone(Request $request)
    {
        $this->validate($request, [
            "country_code" => "required|numeric|min:1|max:9999",
            "phone_number" => "required|numeric|digits_between:7,10|unique:users,mobile|unique:prospects,mobile",
        ]);
        return $this->successResponse("phone validate", []);
    }


    public function requestEmailOtp(Request $request)
    {
        $otpService = new OtpService();
        $otp = $otpService->requestEmailOtp($request->get("email"));
        return $this->successResponse("Email otp request created", $otp->toArray());
    }

    public function requestEmailOtpWebsite(Request $request)
    {


        $otp_code = mt_rand(100000, 999999);
        $uuid = Str::uuid()->toString();
        $otp = new WebEmailVerification();
        $otp->id = $uuid;

        $otp->email = $request->get("email");
        $otp->first_name = $request->get("first_name");
        $otp->last_name = $request->get("last_name");
        $otp->mobile_uuid = $request->get("uuid");
        $otp->code = $otp_code;
        $otp->expiry = (new \DateTime())->modify("+15 minutes");
        $otp->status = self::REQUESTED;
        $otp->saveOrFail();

        $email = $request->get("email");

        $expiresAt = Carbon::now()->addMinutes(30); // Expiration time set to 30 minutes from now

        $resetLink = env('WEBSITE_LINK') . 'verify.php/' . $uuid . '/' . $otp_code . '?expires=' . $expiresAt->timestamp;


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

        $view = "emails.verification-email";

        $data['resetLink'] = $resetLink;
        $data['firstName'] = $request->get("first_name");
        $data['lastName'] =  $request->get("last_name");
        $data['resetLink'] = $resetLink;

        /*   'firstName'=>$request->get("first_name"),
            'lastName'=>$request->get("last_name")*/

        //];


        #create initiate mailable class
        $mailable = new SystemNotificationMail($from, $view, "Verification Link", $data);

        $mailService = new MailService(0, $mailable, $smtpSetting);
        $mailService->sendEmail($email);

        //$subscription->last_sent = Carbon::now();
        //$subscription->save();

        /* $data = [
            'resetLink' => $resetLink,
            'subject' => 'Reset Your Password',
            'firstName'=>$request->get("first_name"),
            'lastName'=>$request->get("last_name")

        ];
    
        Mail::send('emails.verification-email', $data, function ($message) use ($email) {
            $message->to($email)->subject('Reset Your Password');
        });*/



        return $this->successResponse("EMAIL OTP Sent", [$resetLink]);
    }

    /**
     * @OA\Get(
     *     path="/forgot-password-email/email",
     *     summary="Request Password Reset via Email",
     *     description="Sends a password reset link to the user's registered email address.",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="email",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string", format="email"),
     *         description="Registered email address of the user",
     *         example="user@example.com"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password reset link sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="You recently requested to reset your password...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Email not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="That email address is not registered.")
     *         )
     *     )
     * )
     */


    public function requestForgotPasswordEmail(Request $request)
    {
        $user_email = '';
        try {
            $user = User::where('email', $request->get("email"))->firstOrFail();
            $name = $user->first_name;
            $user_email = $user->email;
            $otpService = new OtpService();
            $linkRecord = $otpService->requestForgotPasswordEmail($user_email, $name);
            return $this->successResponse("You recently requested to reset your password has been made,
            please verify it by clicking the activation link that has been send to your email", [$linkRecord]);
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("That email address is not registered. You sure you have an account?", [$user_email], null, 200);
        } catch (\Throwable $exception) {
            return $this->failResponse("That email address is not registered. You sure you have an account?", [$user_email], null, 200);
        }
    }

    public function requestPhoneOtp(Request $request)
    {
        $otpService = new OtpService();
        $otp = $otpService->requestPhoneOtp(
            $request->get("country_code"),
            $request->get("phone_number")
        );
        return $this->successResponse("Phone otp request created", $otp->toArray());
    }


    public function requestPhoneOtpWebsite(Request $request)
    {
        if ($request->get("token_id") != env('WEB_TOKEN')) {
            return $this->failResponse("Invalid Request For Token Id", [$request->get("token_id")], null, 200);
        }
        $otpService = new OtpService();
        $otp = $otpService->requestPhoneOtpWebsite(
            $request->get("country_code"),
            $request->get("phone_number")
        );
        return $this->successResponse("Phone otp request created", $otp->toArray());
    }

    public function verifyOtp(Request $request)
    {

        if ($request->get("token_id") != env('WEB_TOKEN')) {
            return $this->failResponse("Invalid Request For Token Id", [$request->get("token_id")], null, 200);
        }


        $this->validate($request, [
            "type" => "required|in:email,phone",
            "otpId" => "required|uuid",
            "code" => "required|digits:6"
        ]);
        $service = new OtpService();
        try {
            list($success, $response) = $service->verify($request->get("type"), $request->get("otpId"), $request->get("code"));
            if ($success) {
                return $this->successResponse("One Time Password verified. Enter email to proceed", ["Valid otp code"]);
            } else {
                return $this->failResponse("Verification failed", [$response], null, 200);
            }
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid otpId", ["Unable to find " . $request->get("type") . " otp with id " . $request->get("otpId")], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to verify the otp", [$exception->getMessage()], $exception);
        }
    }

    public function verifyOtpLogin(Request $request)
    {
        $this->validate($request, [
            "type" => "required|in:email,phone",
            "otpId" => "required|uuid",
            "code" => "required|digits:6"
        ]);
        $service = new OtpService();
        try {
            list($success, $response) = $service->verifyOtpLogin($request->get("type"), $request->get("otpId"), $request->get("code"));
            if ($success) {
                return $this->successResponse("Otp verified", ["Valid otp code"]);
            } else {
                return $this->failResponse("Verification failed", [$response], null, 200);
            }
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid otpId", ["Unable to find " . $request->get("type") . " otp with id " . $request->get("otpId")], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to verify the otp", [$exception->getMessage()], $exception);
        }
    }


    public function websiteLeadSubmit(Request $request)
    {
        $this->validate($request, [
            "first_name" => "required",
            "last_name" => "required",
            "email" => "required",
            "phone_number" => "required",
            "country_code" => "required",
            "uuid" => "required",


        ]);

        //return $request->all();
        $attributes = $request->all();

        $tariff_label = WebLeads::on("master")->create($attributes);
        $tariff_label->saveOrFail();
        return $this->successResponse("Tariff Label created", $tariff_label->toArray());
    }
    public function OtpMobile(Request $request)
    {
         $this->validate($request,[
            'phone' => 'required|string'
        ]);

        try {
            $accountSid = env('TWILIO_SID');
            $authToken  = env('TWILIO_AUTH_TOKEN');
            $serviceSid = env('TWILIO_VERIFY_SID');

            $twilio = new Client($accountSid, $authToken);

            $verification = $twilio->verify->v2->services($serviceSid)
                ->verifications
                ->create($request->phone, 'sms');

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully!',
                'to' => $verification->to,
                'status' => $verification->status
            ]);
        } catch (\Exception $e) {
            \Log::error("Twilio OTP error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP: ' . $e->getMessage()
            ], 500);
        }
    }

 public function VerifyOtpMobile(Request $request)
{
    // $request->validate([
    //     'phone' => 'required|string',
    //     'code' => 'required|string'
    // ]);

    try {
        $accountSid = env('TWILIO_SID');
        $authToken  = env('TWILIO_AUTH_TOKEN');
        $serviceSid = env('TWILIO_VERIFY_SID');

        $twilio = new Client($accountSid, $authToken);

        $verificationCheck = $twilio->verify->v2->services($serviceSid)
            ->verificationChecks
            ->create([
                'to' => $request->phone,
                'code' => $request->code
            ]);

        if ($verificationCheck->status === 'approved') {
            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully!'
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.'
            ]);
        }

    } catch (\Exception $e) {
        \Log::error("Twilio Verify error: " . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Verification failed: ' . $e->getMessage()
        ], 500);
    }
}

}
