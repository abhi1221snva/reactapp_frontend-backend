<?php

namespace App\Services;
use App\Mail\GenericMail;
use App\Mail\SystemNotificationMail;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\User;
use App\Services\MailService;
class PortalErrorEmailService
{
    public function emailError($subject, array $context)
    {
        $emailBody = view('emails.errorEmailNotification', compact('context'))->render();
        $genericMail = new GenericMail(
            $subject,
            [
                "address" => env('DEFAULT_EMAIL'),
                "name" => "Error Notification"
            ],
            $emailBody
        );
        $errorEmailSetting = new SmtpSetting([
            "mail_driver" => "SMTP",
            "mail_host" => env("ERROR_MAIL_HOST"),
            "mail_port" => env("ERROR_MAIL_PORT"),
            "mail_username" => env("ERROR_MAIL_USERNAME"),
            "mail_password" => env("ERROR_MAIL_PASSWORD"),
            "mail_encryption" => env("ERROR_MAIL_ENCRYPTION"),
            "sender_type" => "system"
        ]);

        $SYSTEM_ADMIN_EMAIL = explode(',', env('SYSTEM_ADMIN_EMAIL'));
        $mailService = new MailService(0, $genericMail, $errorEmailSetting);
        $mailService->sendEmail($SYSTEM_ADMIN_EMAIL);
    }
}
