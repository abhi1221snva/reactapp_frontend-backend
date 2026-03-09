<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\User;
use App\Http\Helper\JwtToken;

class GoogleController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Handle Google OAuth login.
     * Accepts a Google ID token (credential) from the frontend,
     * verifies it with Google's tokeninfo API, then issues a JWT.
     */
    public function handleGoogleCallback(Request $request)
    {
        Log::info('Google OAuth login attempt');

        try {
            $credential = $request->get('credential');

            if (!$credential) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Google credential provided',
                ], 400);
            }

            // Verify the Google ID token against Google's servers
            $tokenInfo = $this->verifyGoogleToken($credential);
            if (!$tokenInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired Google token',
                ], 401);
            }

            $googleId = $tokenInfo['sub'];   // Google's unique user ID
            $email    = $tokenInfo['email'];

            // Try to find user by google_id first, then fall back to email
            $user = User::where('google_id', $googleId)->first();

            if (!$user) {
                $user = User::where('email', $email)->first();

                if (!$user) {
                    Log::warning('Google login: no matching user', ['email' => $email]);
                    return response()->json([
                        'success' => false,
                        'message' => 'No account found for this Google email. Contact your administrator.',
                    ], 401);
                }

                // Link Google ID to the existing account on first use
                $user->google_id = $googleId;
                $user->save();
            }

            if ($user->is_deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account is deactivated',
                ], 403);
            }

            // Generate JWT (same as normal login)
            $tokenPair = JwtToken::createToken($user->id);

            // Build full user payload including role, level, permissions
            $data              = $user->userDetail();
            $data['token']     = $tokenPair[0];
            $data['expires_at'] = $tokenPair[1];

            Log::info('Google OAuth login success', ['user_id' => $user->id, 'email' => $email]);

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data'    => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Google login exception', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify a Google ID token via Google's tokeninfo endpoint.
     * Returns the decoded token claims on success, null on failure.
     */
    private function verifyGoogleToken(string $idToken): ?array
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout'     => 10,
                'http_errors' => false,
            ]);

            $response = $client->get('https://oauth2.googleapis.com/tokeninfo', [
                'query' => ['id_token' => $idToken],
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::warning('Google tokeninfo non-200', ['status' => $response->getStatusCode()]);
                return null;
            }

            $info = json_decode((string) $response->getBody(), true);

            if (!isset($info['sub'], $info['email'], $info['aud'])) {
                Log::warning('Google tokeninfo missing fields');
                return null;
            }

            // Ensure the token was issued for our app
            $clientId = env('GOOGLE_CLIENT_ID');
            if ($info['aud'] !== $clientId) {
                Log::warning('Google token audience mismatch', [
                    'aud'      => $info['aud'],
                    'expected' => $clientId,
                ]);
                return null;
            }

            // Check expiry
            if (isset($info['exp']) && (int) $info['exp'] < time()) {
                Log::warning('Google token expired');
                return null;
            }

            return $info;

        } catch (\Exception $e) {
            Log::error('Google token verification error', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
