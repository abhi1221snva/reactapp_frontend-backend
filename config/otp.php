<?php

return [
    "email" => [
        "mail_driver" => "smtp",
        "mail_host" => env("OTP_MAIL_HOST"),
        "mail_port" => env("OTP_MAIL_PORT"),
        "mail_username" => env("OTP_MAIL_USERNAME"),
        "mail_password" => env("OTP_MAIL_PASSWORD"),
        "mail_encryption" => env("OTP_MAIL_ENCRYPTION"),
        "sender_type" => "system",
        "campaign_id" => null,
        "user_id" => null,
        "from_email" => env("OTP_MAIL_USERNAME"),
        "from_name" => env("SITE_NAME")
    ],
    "sms" => [
        "url" => env("SMS_SERVICE_URL"),
        "key" => env("SMS_SERVICE_KEY"),
        "token" => env("SMS_SERVICE_TOKEN"),
        "from_number" => env("SMS_FROM_NUMBER"),
        "message" => "Your OTP for signing up on ".env("SITE_NAME")." is {otp}."
    ]
];
