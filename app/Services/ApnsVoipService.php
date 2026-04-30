<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

class ApnsVoipService
{
    /**
     * Send a VoIP push notification via APNs HTTP/2.
     *
     * @param string $deviceToken  The PushKit VoIP device token (hex string)
     * @param array  $payload      The push payload (must contain caller info for CallKit)
     * @return array               ['success' => bool, 'status_code' => int, 'response' => string]
     */
    public static function send(string $deviceToken, array $payload): array
    {
        $keyId    = env('APNS_KEY_ID');
        $teamId   = env('APNS_TEAM_ID');
        $bundleId = env('APNS_BUNDLE_ID', 'com.linkswitchcommunications.phoneapp');
        $keyPath  = base_path(env('APNS_AUTH_KEY_PATH', 'storage/app/apns_auth_key.p8'));
        $isProduction = filter_var(env('APNS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN);

        if (!$keyId || !$teamId) {
            Log::error('APNs VoIP push aborted: APNS_KEY_ID or APNS_TEAM_ID not configured');
            return ['success' => false, 'status_code' => 0, 'response' => 'Missing APNS configuration'];
        }

        if (!file_exists($keyPath)) {
            Log::error('APNs VoIP push aborted: .p8 key file not found', ['path' => $keyPath]);
            return ['success' => false, 'status_code' => 0, 'response' => 'APNs key file not found'];
        }

        // Build the JWT for APNs token-based authentication
        $jwt = self::generateJwt($keyId, $teamId, $keyPath);
        if (!$jwt) {
            return ['success' => false, 'status_code' => 0, 'response' => 'Failed to generate APNs JWT'];
        }

        // VoIP pushes use the .voip topic suffix
        $topic = $bundleId . '.voip';

        $host = $isProduction
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $url = "{$host}/3/device/{$deviceToken}";

        $jsonPayload = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                "authorization: bearer {$jwt}",
                "apns-topic: {$topic}",
                "apns-push-type: voip",
                "apns-priority: 10",
                "apns-expiration: 0",
                "content-type: application/json",
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error('APNs VoIP push curl error', [
                'error'  => $curlError,
                'token'  => substr($deviceToken, 0, 12) . '...',
            ]);
            return ['success' => false, 'status_code' => 0, 'response' => $curlError];
        }

        $success = $httpCode === 200;

        if (!$success) {
            Log::warning('APNs VoIP push failed', [
                'http_code' => $httpCode,
                'response'  => $responseBody,
                'token'     => substr($deviceToken, 0, 12) . '...',
            ]);
        } else {
            Log::info('APNs VoIP push sent', [
                'token' => substr($deviceToken, 0, 12) . '...',
            ]);
        }

        return [
            'success'     => $success,
            'status_code' => $httpCode,
            'response'    => $responseBody ?: 'OK',
        ];
    }

    /**
     * Build a CallKit-compatible VoIP push payload.
     *
     * If caller_name is missing or identical to caller_number, the method
     * tries to resolve a human-readable name from:
     *   1. CRM leads (crm_lead_values phone fields) on the user's tenant DB
     *   2. Internal team users (mobile, phone, extension)
     * Falls back to the raw number if nothing is found.
     */
    public static function buildCallPayload(
        string  $callerNumber,
        string  $callerName,
        ?string $callUuid = null,
        ?int    $userId = null
    ): array {
        // If caller_name is empty or just the number, try to enrich it
        if (!$callerName || $callerName === $callerNumber) {
            $resolved = self::resolveCallerName($callerNumber, $userId);
            if ($resolved) {
                $callerName = $resolved;
            }
        }

        return [
            'aps' => [
                'content-available' => 1,
            ],
            'uuid'          => $callUuid ?: self::generateUuid(),
            'caller_id'     => $callerNumber,
            'caller_name'   => $callerName ?: $callerNumber,
            'handle'        => $callerNumber,
            'type'          => 'incoming_call',
        ];
    }

    /**
     * Try to resolve a caller name from CRM leads or internal users.
     *
     * @param string   $number  The caller's phone number
     * @param int|null $userId  The target user — used to determine the tenant DB
     * @return string|null      A human-readable name, or null
     */
    public static function resolveCallerName(string $number, ?int $userId = null): ?string
    {
        $digits = preg_replace('/\D/', '', $number);
        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;

        try {
            // Determine tenant (parent_id) for this user
            $parentId = null;
            if ($userId) {
                $user = \Illuminate\Support\Facades\DB::connection('master')
                    ->table('users')
                    ->where('id', $userId)
                    ->select('parent_id')
                    ->first();
                $parentId = $user->parent_id ?? null;
            }

            // 1. Search CRM leads on tenant DB
            if ($parentId) {
                $tenantConn = 'mysql_' . $parentId;
                try {
                    // Look in crm_lead_values for phone-related fields
                    $leadId = \Illuminate\Support\Facades\DB::connection($tenantConn)
                        ->table('crm_lead_values')
                        ->whereIn('field_key', ['phone', 'phone_number', 'mobile', 'cell_phone', 'work_phone', 'home_phone'])
                        ->where(function ($q) use ($number, $last10) {
                            $q->where('field_value', $number)
                              ->orWhere('field_value', $last10)
                              ->orWhere(\Illuminate\Support\Facades\DB::raw("RIGHT(REPLACE(REPLACE(REPLACE(field_value, '-', ''), '(', ''), ')', ''), 10)"), $last10);
                        })
                        ->value('lead_id');

                    if ($leadId) {
                        // Get first_name + last_name from EAV
                        $names = \Illuminate\Support\Facades\DB::connection($tenantConn)
                            ->table('crm_lead_values')
                            ->where('lead_id', $leadId)
                            ->whereIn('field_key', ['first_name', 'last_name'])
                            ->pluck('field_value', 'field_key');

                        $fullName = trim(($names['first_name'] ?? '') . ' ' . ($names['last_name'] ?? ''));
                        if ($fullName) {
                            return $fullName;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::debug('resolveCallerName: CRM lookup failed', ['error' => $e->getMessage()]);
                }
            }

            // 2. Search internal users by phone/mobile/extension
            $internalUser = \Illuminate\Support\Facades\DB::connection('master')
                ->table('users')
                ->where(function ($q) use ($number, $last10) {
                    $q->where('mobile', $number)
                      ->orWhere('mobile', $last10)
                      ->orWhere('phone', $number)
                      ->orWhere('phone', $last10)
                      ->orWhere('extension', $last10)
                      ->orWhere('alt_extension', $last10)
                      ->orWhere('app_extension', $last10);
                })
                ->select('first_name', 'last_name')
                ->first();

            if ($internalUser) {
                $name = trim(($internalUser->first_name ?? '') . ' ' . ($internalUser->last_name ?? ''));
                if ($name) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('resolveCallerName: lookup failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Generate an ES256 JWT for APNs token-based auth.
     */
    protected static function generateJwt(string $keyId, string $teamId, string $keyPath): ?string
    {
        try {
            $privateKey = file_get_contents($keyPath);

            $header = [
                'alg' => 'ES256',
                'kid' => $keyId,
            ];

            $claims = [
                'iss' => $teamId,
                'iat' => time(),
            ];

            return JWT::encode($claims, $privateKey, 'ES256', $keyId, $header);
        } catch (\Exception $e) {
            Log::error('APNs JWT generation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate a v4 UUID.
     */
    protected static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
