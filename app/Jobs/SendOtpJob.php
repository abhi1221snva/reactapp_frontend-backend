<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Services\MailService;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class SendOtpJob extends Job
{
    private $otpRequest;
    private $setting;

    /**
     * Create a new job instance.
     * 1.	Requested
     * 2.	Processing
     * 3.	Sent
     * 4.	Failed
     * 5.	Verified
     * @return void
     */
    public function __construct($otpRequest, array $setting)
    {
        $this->otpRequest = $otpRequest;
        $this->setting = $setting;
    }

    /**
     * Execute the job.
     * @return void
     */
    public function handle()
    {
        try {
            #todo: Add condition of expired time.
            if (in_array($this->otpRequest->status, [OtpService::REQUESTED, OtpService::FAILED])) {
                $this->otpRequest->status = OtpService::PROCESSING;
                $this->otpRequest->save();

                $otp = $this->otpRequest->toArray();
                if (isset($otp['email'])) {
                    #send otp email
                    #create initiate mailable class
                    $from = [
                        "address" => $this->setting["smtp"]->from_email,
                        "name" => $this->setting["smtp"]->from_name,
                    ];
                    $data = ["code" => $this->otpRequest->code];
                    $mailable = new SystemNotificationMail($from, $this->setting["view"], $this->setting["subject"], $data);

                    $mailService = new MailService(0, $mailable, $this->setting["smtp"]);
                    $mailService->sendEmail($this->otpRequest->email);

                    $this->otpRequest->status = OtpService::SENT;
                } elseif (isset($otp['country_code']) && isset($otp['phone_number'])) {
                    #send otp sms
                    $smsService = new SmsService($this->setting["url"], $this->setting["key"], $this->setting["token"]);
                    $response = $smsService->sendMessage(
                        $this->setting["from_number"],
                        $this->otpRequest->country_code.$this->otpRequest->phone_number,
                        str_replace("{otp}", $this->otpRequest->code, $this->setting["message"])
                    );
                    Log::debug("SendOtpJob.sendMessage.response", [$response]);
                    $this->otpRequest->status = OtpService::SENT;
                } else {
                    $this->otpRequest->status = OtpService::INVALID;
                }
                $this->otpRequest->save();
            }
        } catch (\Throwable $throwable) {
            $context = buildContext($throwable);
            $context["otpRequest"] = $this->otpRequest;
            $context["setting"] = $this->setting;
            Log::error("SendOtpJob.handle failed", $context);

            $this->otpRequest->status = OtpService::FAILED;
            $this->otpRequest->save();
        }
    }
}
