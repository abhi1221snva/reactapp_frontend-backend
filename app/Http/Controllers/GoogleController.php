<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

            // Try to find user by google_id first, then fall back to email.
            // IMPORTANT: Only match active (non-deleted) users to prevent
            // cross-tenant impersonation via shared email addresses.
            $user = User::where('google_id', $googleId)
                ->where('is_deleted', 0)
                ->first();

            // SECURITY: If found by google_id, verify the email matches.
            // A stale or mislinked google_id must NOT grant access to a
            // different user's account.
            if ($user && strtolower($user->email) !== strtolower($email)) {
                Log::warning('Google login: google_id/email mismatch — clearing stale link', [
                    'user_id'      => $user->id,
                    'stored_email' => $user->email,
                    'google_email' => $email,
                    'google_id'    => $googleId,
                ]);

                // Clear the stale google_id so it won't match again
                $user->google_id = null;
                $user->save();

                // Do NOT use this user — fall through to email lookup
                $user = null;
            }

            if (!$user) {
                // Search ALL users (including deleted) to give the right error message
                $user = User::where('email', $email)->first();

                if (!$user) {
                    Log::warning('Google login: no matching user', ['email' => $email]);
                    return response()->json([
                        'success' => false,
                        'code'    => 'ACCOUNT_NOT_FOUND',
                        'message' => 'This Google account is not registered in the system. Please sign up first.',
                    ], 422);
                }

                if ($user->is_deleted) {
                    return response()->json([
                        'success' => false,
                        'code'    => 'ACCOUNT_DEACTIVATED',
                        'message' => 'Your account has been deactivated. Please contact support.',
                    ], 403);
                }

                // Require password verification before linking Google ID
                return response()->json([
                    'success' => false,
                    'code'    => 'GOOGLE_LINK_REQUIRED',
                    'message' => 'Enter your account password to link Google login.',
                    'data'    => ['email' => $email, 'credential' => $credential],
                ], 200);
            }

            if ($user->is_deleted) {
                return response()->json([
                    'success' => false,
                    'code'    => 'ACCOUNT_DEACTIVATED',
                    'message' => 'Your account has been deactivated. Please contact support.',
                ], 403);
            }

            if (isset($user->status) && $user->status == 0) {
                return response()->json([
                    'success' => false,
                    'code'    => 'ACCOUNT_INACTIVE',
                    'message' => 'Your account is not active. Please contact support.',
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
            Log::error('Google login exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
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

    /**
     * Link a Google account to an existing user after password verification.
     * Accepts the Google credential + user's password, verifies both,
     * then links the google_id and returns a JWT.
     */
    public function linkGoogleAccount(Request $request)
    {
        $this->validate($request, [
            'credential' => 'required|string',
            'password'   => 'required|string',
        ]);

        try {
            $credential = $request->input('credential');
            $password   = $request->input('password');

            $tokenInfo = $this->verifyGoogleToken($credential);
            if (!$tokenInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired Google token.',
                ], 401);
            }

            $googleId = $tokenInfo['sub'];
            $email    = $tokenInfo['email'];

            $user = User::where('email', $email)->where('is_deleted', 0)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found.',
                ], 404);
            }

            if (!Hash::check($password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect password.',
                ], 401);
            }

            // Password verified — link Google ID
            $user->google_id = $googleId;
            $user->save();

            // Generate JWT
            $tokenPair = JwtToken::createToken($user->id);

            $data              = $user->userDetail();
            $data['token']     = $tokenPair[0];
            $data['expires_at'] = $tokenPair[1];

            Log::info('Google account linked via password verification', [
                'user_id' => $user->id,
                'email'   => $email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Google account linked successfully.',
                'data'    => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Google link exception', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to link Google account. Please try again.',
            ], 500);
        }
    }
}
