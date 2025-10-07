<?php

namespace App\Services;

use Plivo\RestClient;
use Plivo\Resources\Message\MessageCreateResponse;
use Plivo\Resources\Message\MessageCreateErrorResponse;
use Illuminate\Support\Facades\Cache;

class OtpServices
{
    public function sendOtp($phoneNumber)
    {
        $otp = rand(100000, 999999); // Generate 6-digit OTP
        $brandName = 'Voiptella';//env('OTP_BRAND_NAME', 'CallChex');
        $validityMinutes = 15;//env('OTP_VALIDITY_MINUTES', 10);

        $client = new RestClient(env('PLIVO_AUTH_ID'),env('PLIVO_AUTH_TOKEN'));

        $message = "$brandName: Your verification code is $otp. It’s valid for $validityMinutes minutes. Do not share this code.";

        try {
            $response = $client->messages->create(env('PLIVO_SOURCE_NUMBER'),
                [$phoneNumber],
                $message
            );


            if ($response instanceof MessageCreateResponse) {
                return [
                    'success' => true,
                    'otp' => $otp,
                    'message' => 'OTP sent successfully.',
                    'plivo_response' => [
                        'message_uuid' => $response->messageUuid,
                        'api_id' => $response->apiId,
                        'message' => $response->message
                    ]
                ];
            } elseif ($response instanceof MessageCreateErrorResponse) {
                return [
                    'success' => false,
                    'message' => 'Plivo error: ' . ($response->error ?? 'Unknown error'),
                    'plivo_response' => (array) $response
                ];
            }

            return [
                'success' => false,
                'message' => 'Unexpected response from Plivo.',
                'plivo_response' => (array) $response
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception while sending OTP: ' . $e->getMessage()
            ];
        }
    }
}
