<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * SMS Gateway abstraction layer.
 *
 * Supports Plivo, Twilio, DIDForSale (MSG91-style).
 * Falls back to logging when no gateway is configured.
 *
 * Usage:
 *   $sms = new SmsGatewayService();
 *   $sms->send('+14155551234', 'Your OTP is 123456');
 */
class SmsGatewayService
{
    /** @var string Active gateway: plivo | twilio | didsale | log */
    private string $driver;

    public function __construct()
    {
        $this->driver = strtolower(env('SMS_DRIVER', 'log'));
    }

    /**
     * Send an SMS message.
     *
     * @param  string $to      Recipient phone in E.164 format (e.g. +14155551234)
     * @param  string $message Message body
     * @return array           ['success' => bool, 'message' => string]
     */
    public function send(string $to, string $message): array
    {
        switch ($this->driver) {
            case 'plivo':
                return $this->sendViaPlivo($to, $message);
            case 'twilio':
                return $this->sendViaTwilio($to, $message);
            case 'didsale':
                return $this->sendViaDidsale($to, $message);
            default:
                return $this->sendViaLog($to, $message);
        }
    }

    /**
     * Send OTP via SMS — convenience wrapper that formats the OTP message.
     */
    public function sendOtp(string $to, string $otp): array
    {
        $siteName = env('SITE_NAME', 'Dialer');
        $message  = "Your {$siteName} verification code is {$otp}. Valid for 15 minutes. Do not share this code.";
        return $this->send($to, $message);
    }

    // ----------------------------------------------------------------
    // Gateway implementations
    // ----------------------------------------------------------------

    private function sendViaPlivo(string $to, string $message): array
    {
        try {
            $user = env('PLIVO_USER');
            $pass = env('PLIVO_PASS');
            $from = env('PLIVO_SMS_NUMBER');

            if (empty($user) || empty($pass) || empty($from)) {
                Log::warning('SmsGatewayService: Plivo credentials not configured, falling back to log.');
                return $this->sendViaLog($to, $message);
            }

            $client = new \Plivo\RestClient($user, $pass);
            $result = $client->messages->create([
                'src'  => $from,
                'dst'  => $to,
                'text' => $message,
            ]);

            Log::info('SmsGatewayService: Plivo SMS sent', ['to' => $to, 'response' => $result]);
            return ['success' => true, 'message' => 'SMS sent via Plivo'];
        } catch (\Throwable $e) {
            Log::error('SmsGatewayService: Plivo error', ['error' => $e->getMessage(), 'to' => $to]);
            return ['success' => false, 'message' => 'Plivo error: ' . $e->getMessage()];
        }
    }

    private function sendViaTwilio(string $to, string $message): array
    {
        try {
            $sid   = env('TWILIO_SID');
            $token = env('TWILIO_AUTH_TOKEN');
            $from  = env('TWILIO_FROM_NUMBER', env('PLIVO_SMS_NUMBER'));

            if (empty($sid) || empty($token) || empty($from)) {
                Log::warning('SmsGatewayService: Twilio credentials not configured, falling back to log.');
                return $this->sendViaLog($to, $message);
            }

            $twilio = new \Twilio\Rest\Client($sid, $token);
            $result = $twilio->messages->create($to, [
                'from' => $from,
                'body' => $message,
            ]);

            Log::info('SmsGatewayService: Twilio SMS sent', ['to' => $to, 'sid' => $result->sid]);
            return ['success' => true, 'message' => 'SMS sent via Twilio'];
        } catch (\Throwable $e) {
            Log::error('SmsGatewayService: Twilio error', ['error' => $e->getMessage(), 'to' => $to]);
            return ['success' => false, 'message' => 'Twilio error: ' . $e->getMessage()];
        }
    }

    private function sendViaDidsale(string $to, string $message): array
    {
        try {
            $apiKey  = env('SMS_API');
            $access  = env('SMS_ACCESS');
            $url     = env('SMS_URL_API');
            $from    = env('SMS_NUMBER');

            if (empty($apiKey) || empty($url)) {
                Log::warning('SmsGatewayService: DIDForSale credentials not configured, falling back to log.');
                return $this->sendViaLog($to, $message);
            }

            $payload = json_encode([
                'to'   => $to,
                'from' => $from,
                'text' => $message,
            ]);

            $response = \GuzzleHttp\Client::create()->post($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode("{$apiKey}:{$access}"),
                ],
                'body' => $payload,
            ]);

            Log::info('SmsGatewayService: DIDForSale SMS sent', ['to' => $to]);
            return ['success' => true, 'message' => 'SMS sent via DIDForSale'];
        } catch (\Throwable $e) {
            Log::error('SmsGatewayService: DIDForSale error', ['error' => $e->getMessage(), 'to' => $to]);
            return ['success' => false, 'message' => 'DIDForSale error: ' . $e->getMessage()];
        }
    }

    /**
     * Development/fallback: log the SMS instead of sending.
     */
    private function sendViaLog(string $to, string $message): array
    {
        Log::info('SmsGatewayService [LOG DRIVER]: SMS not sent — logged only.', [
            'to'      => $to,
            'message' => $message,
        ]);
        return ['success' => true, 'message' => 'SMS logged (no gateway configured)'];
    }
}
