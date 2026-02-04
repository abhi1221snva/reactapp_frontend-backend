<?php

namespace App\Services;

use App\Model\UserFcmToken;
use Exception;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    /**
     * Get an OAuth2 Access Token for FCM HTTP v1 API
     */
    protected static function getAccessToken()
    {
        try {
            $config = config('firebase');
            $credentialsFile = $config['credentials_file'];

            if (!file_exists($credentialsFile)) {
                throw new Exception("Firebase credentials file not found at: {$credentialsFile}");
            }

            $credentials = json_decode(file_get_contents($credentialsFile), true);

            $now = time();
            $payload = [
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $jwt = JWT::encode($payload, $credentials['private_key'], 'RS256');

            $client = new Client();
            $response = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['access_token'];
        } catch (Exception $e) {
            Log::error('Firebase getAccessToken failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send notification to multiple device tokens
     */
    public static function sendNotification(array $tokens, string $title, string $body, array $data = [])
    {
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            Log::error('FCM sendNotification aborted: Access token could not be generated.');
            return false;
        }

        $projectId = config('firebase.project_id');
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $client = new Client();

        $results = [];
        foreach ($tokens as $token) {
            try {
                $payload = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => array_map('strval', $data), // FCM data values must be strings
                        'webpush' => [
                            'fcm_options' => [
                                'link' => $data['link'] ?? '/',
                            ]
                        ],
                    ]
                ];

                $response = $client->post($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]);

                $results[] = [
                    'token' => $token,
                    'status' => 'success',
                    'response' => json_decode($response->getBody(), true)
                ];
            } catch (Exception $e) {
                $responseBody = $e instanceof \GuzzleHttp\Exception\ClientException 
                    ? json_decode($e->getResponse()->getBody(), true) 
                    : [];

                Log::warning('FCM send failed for token', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                    'response' => $responseBody
                ]);

                // If token is invalid or not registered, remove it from our DB
                if (isset($responseBody['error']['status']) && in_array($responseBody['error']['status'], ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED'])) {
                    UserFcmToken::where('device_token', $token)->delete();
                    Log::info('Deleted invalid FCM token from database', ['token' => $token]);
                }

                $results[] = [
                    'token' => $token,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}
