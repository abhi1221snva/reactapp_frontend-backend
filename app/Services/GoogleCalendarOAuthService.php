<?php

namespace App\Services;

use App\Model\Master\GoogleCalendarToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleCalendarOAuthService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected array $scopes;

    protected const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    protected const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
    protected const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public function __construct()
    {
        $this->clientId     = config('services.google.client_id');
        $this->clientSecret = config('services.google.client_secret');
        $this->redirectUri  = config('services.google.calendar_redirect', config('services.google.redirect'));
        $this->scopes       = config('services.google.calendar_scopes', [
            'https://www.googleapis.com/auth/calendar',
            'email',
            'profile',
        ]);
    }

    /**
     * Generate the Google OAuth authorization URL for Calendar.
     */
    public function getAuthorizationUrl(int $userId): string
    {
        $state = base64_encode(json_encode([
            'user_id' => $userId,
            'nonce'   => Str::random(32),
        ]));

        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $this->scopes),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Parse and validate the state parameter.
     */
    public function parseState(string $state): ?array
    {
        try {
            $decoded = json_decode(base64_decode($state), true);
            if (isset($decoded['user_id'], $decoded['nonce'])) {
                return $decoded;
            }
        } catch (\Exception $e) {
            Log::error('Google Calendar OAuth: Invalid state parameter', ['error' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * Exchange authorization code for access and refresh tokens.
     */
    public function exchangeCodeForTokens(string $code, int $userId): ?GoogleCalendarToken
    {
        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri,
            ]);

            if (!$response->successful()) {
                Log::error('Google Calendar OAuth: Token exchange failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            // Get user email from userinfo endpoint
            $email = $this->getUserEmail($data['access_token']);

            // Upsert token record
            $token = GoogleCalendarToken::updateOrCreate(
                ['user_id' => $userId],
                [
                    'calendar_email'   => $email,
                    'access_token'     => $data['access_token'],
                    'refresh_token'    => $data['refresh_token'] ?? null,
                    'token_expires_at' => isset($data['expires_in'])
                        ? Carbon::now()->addSeconds($data['expires_in'])
                        : null,
                    'scope'     => $data['scope'] ?? null,
                    'is_active' => true,
                ]
            );

            return $token;

        } catch (\Throwable $e) {
            Log::error('Google Calendar OAuth: exchangeCodeForTokens exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Refresh the access token using the stored refresh token.
     */
    public function refreshAccessToken(GoogleCalendarToken $token): ?GoogleCalendarToken
    {
        $refreshToken = $token->getDecryptedRefreshToken();
        if (!$refreshToken) {
            Log::warning('Google Calendar OAuth: cannot refresh — no valid refresh token stored', [
                'user_id' => $token->user_id,
            ]);
            return null;
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);

            if (!$response->successful()) {
                Log::error('Google Calendar OAuth: Token refresh failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            $token->access_token     = $data['access_token'];
            $token->token_expires_at = isset($data['expires_in'])
                ? Carbon::now()->addSeconds($data['expires_in'])
                : null;
            $token->save();

            return $token;

        } catch (\Throwable $e) {
            Log::error('Google Calendar OAuth: refreshAccessToken exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get a valid access token, refreshing if necessary.
     */
    public function getValidAccessToken(int $userId): ?string
    {
        $token = GoogleCalendarToken::getActiveForUser($userId);
        if (!$token) {
            return null;
        }

        if ($token->isExpiringSoon()) {
            $refreshed = $this->refreshAccessToken($token);

            if ($refreshed) {
                return $refreshed->getDecryptedAccessToken();
            }

            // Refresh failed. If the token hasn't actually crossed its expiry boundary
            // yet (e.g. we're within the 5-minute pre-expiry window but still valid),
            // attempt to use the existing access token rather than failing immediately.
            if (!$token->isExpired()) {
                Log::warning('Google Calendar OAuth: refresh failed but token not yet expired — using existing access token', [
                    'user_id'          => $userId,
                    'token_expires_at' => $token->token_expires_at?->toIso8601String(),
                ]);
                return $token->getDecryptedAccessToken();
            }

            // Token is genuinely expired and refresh failed — user must reconnect.
            Log::error('Google Calendar OAuth: token expired and refresh failed — reconnection required', [
                'user_id'          => $userId,
                'token_expires_at' => $token->token_expires_at?->toIso8601String(),
            ]);
            return null;
        }

        return $token->getDecryptedAccessToken();
    }

    /**
     * Revoke OAuth access and remove the token record.
     */
    public function revokeAccess(int $userId): bool
    {
        $token = GoogleCalendarToken::getActiveForUser($userId);
        if (!$token) {
            return true;
        }

        try {
            $accessToken = $token->getDecryptedAccessToken();
            if ($accessToken) {
                Http::post(self::REVOKE_URL, ['token' => $accessToken]);
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar OAuth: revoke request failed', ['error' => $e->getMessage()]);
        }

        $token->delete();
        return true;
    }

    /**
     * Check if the user has an active Calendar connection.
     */
    public function hasActiveConnection(int $userId): bool
    {
        return GoogleCalendarToken::getActiveForUser($userId) !== null;
    }

    /**
     * Get connection status info for the user.
     */
    public function getConnectionStatus(int $userId): array
    {
        $token = GoogleCalendarToken::getActiveForUser($userId);

        if (!$token) {
            return [
                'connected'    => false,
                'account'      => null,
                'connected_at' => null,
            ];
        }

        return [
            'connected'    => true,
            'account'      => $token->calendar_email,
            'connected_at' => $token->created_at?->toIso8601String(),
        ];
    }

    /**
     * Fetch the user's email from Google userinfo endpoint.
     */
    protected function getUserEmail(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)->get(self::USERINFO_URL);
            if ($response->successful()) {
                return $response->json('email');
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar OAuth: getUserEmail failed', ['error' => $e->getMessage()]);
        }
        return null;
    }
}
