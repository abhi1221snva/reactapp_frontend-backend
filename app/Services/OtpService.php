<?php


namespace App\Services;

use App\Jobs\SendOtpJob;
use App\Model\Client\SmtpSetting;
use App\Model\Master\EmailVerification;
use App\Model\Master\PhoneVerification;
use App\Model\Master\WebPhoneVerification;

use App\Model\Master\OtpVerification;
use App\Model\Master\ForgotPasswordLink;
use App\Mail\SystemNotificationMail;
use App\Services\PortalErrorEmailService;
use Illuminate\Support\Facades\Log;
use Plivo\RestClient;


use Illuminate\Support\Str;

class OtpService
{
    const REQUESTED = 1;
    const PROCESSING = 2;
    const SENT = 3;
    const VERIFIED = 4;
    const FAILED = 5;
    const INVALID = 6;

    public function requestEmailOtp(string $email): EmailVerification
    {
        try
        {
            $otp_code =mt_rand(100000, 999999);
            $otp = new EmailVerification();
            $otp->id = Str::uuid()->toString();
            $otp->email = $email;
            $otp->code = $otp_code;
            $otp->expiry = (new \DateTime())->modify("+15 minutes");
            $otp->status = self::REQUESTED;
            $otp->saveOrFail();
            /*$setting = [
            "smtp" => new SmtpSetting(config("otp.email")),
            "view" => "emails.signupOtp",
            "subject" => "OTP for signing up on ".env("SITE_NAME")
            ];
            dispatch(new SendOtpJob($otp, $setting))->onConnection("database")->onQueue("otp");*/

            $subject = "OTP for signing up on ".env("SITE_NAME");
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

            $this->data["Link"]["code"] = $otp_code;
            $mailable = new SystemNotificationMail($from, "emails.signupOtp", $subject, $this->data);
            $mailService = new MailService(0, $mailable, $smtpSetting);
            $mailService->sendEmail($email);

            Log::debug("SendOtpEmailVerification.sendEmailOtp.responseEmail", [$email,$otp_code]);
        }
        catch (\Throwable $throwable)
        {
            $context = buildContext($throwable, [
                "Error page" => '\backend\app\Services\OtpService - function (requestEmailOtp)'
            ]);
            Log::error("New Registration On Website domain.email.error(Email Error)", $context);
            $PortalErrorEmailService = new PortalErrorEmailService();
            $PortalErrorEmailServiceValue = $PortalErrorEmailService->emailError("Registration On Website domain.handle.error(Email Error)", $context);
        }
        return $otp;
    }

    
    public function requestForgotPasswordEmail(string $email,string $name)
    {
        $uuid = Str::uuid()->toString();
        $linkRecord = new ForgotPasswordLink();
        $linkRecord->id = $uuid;
        $linkRecord->email = $email;
        $linkRecord->status = self::REQUESTED;
        $linkRecord->saveOrFail();
        
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

                $this->data["Link"]["url"] = env('PORTAL_NAME').'forgot-password/'.$uuid;
                //$this->data["Link"]["url"] = 'http://localhost:8090/forgot-password/'.$uuid;
                $this->data["Link"]["name"] = $name;
                $this->data["action"] = 'Password Reset Url';

                $mailable = new SystemNotificationMail($from, "emails.forgot-password", $this->data["action"], $this->data);

            $mailService = new MailService(0, $mailable, $smtpSetting);
            $mailService->sendEmail($email);
        
        return $linkRecord;
    }

    public function requestPhoneOtp(int $countryCode, string $phoneNumber)
    {
        $otp = new PhoneVerification();
        $otp->id = Str::uuid()->toString();
        $otp->country_code = $countryCode;
        $otp->phone_number = $phoneNumber;
        $otp->code = mt_rand(100000, 999999);
        $otp->expiry = (new \DateTime())->modify("+15 minutes");
        $otp->status = self::REQUESTED;
        $otp->saveOrFail();
        $setting = config("otp.sms");
        //dispatch(new SendOtpJob($otp, $setting))->onConnection("database")->onQueue("otp");
        dispatch(new SendOtpJob($otp, $setting))->onConnection("database");
        return $otp;
    }


    public function requestPhoneOtpWebsite(int $countryCode, string $phoneNumber)
    {

        $otp_value = mt_rand(100000, 999999);

        $otp = new WebPhoneVerification();
        $otp->id = Str::uuid()->toString();
        $otp->country_code = $countryCode;
        $otp->phone_number = $phoneNumber;
        $otp->code = $otp_value;
        $otp->expiry = (new \DateTime())->modify("+15 minutes");
        $otp->status = self::REQUESTED;
        $otp->saveOrFail();

        /*$data_array = array();
        $to = $countryCode.$phoneNumber;

        $data_array['to'] = $to;
        $data_array['text'] = "Your Verification OTP for ".env('SITE_NAME')." is ".$otp_value;
        $json_data_to_send = json_encode($data_array);

        $data_array['from'] = env('PLIVO_SMS_NUMBER');
        $plivo_user = env('PLIVO_USER');
        $plivo_pass = env('PLIVO_PASS');

        $client = new RestClient($plivo_user,$plivo_pass);
        $result = $client->messages->create([ 
            "src" => $data_array['from'],
            "dst" => $data_array['to'],
            "text"  =>$data_array['text'],
            "url"=>""
        ]);*/

        return $otp;
    }

    public function verify(string $type, string $id, int $code)
    {
        #First fetch the OTP
        if ($type === "email") {
            $otp = EmailVerification::findOrFail($id);
        } elseif ($type === "phone") {
            $otp = WebPhoneVerification::findOrFail($id);
        } else {
            throw new \Exception("Invalid otp type '$type' passed in OtpService.verify");
        }

        #Should be within expiry time
        if (time() > strtotime($otp->expiry)) {
            return [false, "Expired"];
        }
        if ($otp->status === self::VERIFIED) {
            return [false, "Already verified"];
        }

        if ($otp->code === $code) {
            $otp->status = self::VERIFIED;
            $otp->saveOrFail();
            return [true, "Verified"];
        }

        #if otp was not marked verified, mark it now as failed
        $otp->status = self::FAILED;
        $otp->saveOrFail();
        return [false, "Invalid otp code"];
    }

    public function verifyOtpLogin(string $type, string $id, int $code)
    {
        #First fetch the OTP
        if ($type === "email") {
            $otp = EmailVerification::findOrFail($id);
        } elseif ($type === "phone") {
            $otp = OtpVerification::findOrFail($id);
        } else {
            throw new \Exception("Invalid otp type '$type' passed in OtpService.verify");
        }

        #Should be within expiry time
        if (time() > strtotime($otp->expiry)) {
            return [false, "Expired"];
        }
        if ($otp->status === self::VERIFIED) {
            return [false, "Already verified"];
        }

        if ($otp->code === $code) {
            $otp->status = self::VERIFIED;
            $otp->saveOrFail();
            return [true, "Verified"];
        }

        #if otp was not marked verified, mark it now as failed
        $otp->status = self::FAILED;
        $otp->saveOrFail();
        return [false, "Invalid otp code"];
    }
}
