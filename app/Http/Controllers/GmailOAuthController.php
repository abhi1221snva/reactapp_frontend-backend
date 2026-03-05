<?php

namespace App\Http\Controllers;

use App\Model\Client\GmailNotificationSetting;
use App\Model\Master\GmailOAuthToken;
use App\Services\GmailOAuthService;
use Illuminate\Http\Request;

class GmailOAuthController extends Controller
{
    protected $oauthService = null;

    protected function getOAuthService()
    {
        if (!$this->oauthService) {
            $this->oauthService = new GmailOAuthService();
        }
        return $this->oauthService;
    }

    /**
     * Initiate Gmail OAuth flow.
     * Returns the authorization URL to redirect the user to.
     */
    public function connect(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $authUrl = $this->getOAuthService()->getAuthorizationUrl($userId);

            return $this->successResponse("Authorization URL generated", [
                'auth_url' => $authUrl,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to generate authorization URL", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Handle OAuth callback from Google.
     */
    public function callback(Request $request)
    {
        $this->validate($request, [
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $code = $request->input('code');
            $state = $request->input('state');

            // Parse and validate state
            $stateData = $this->getOAuthService()->parseState($state);
            if (!$stateData) {
                return $this->failResponse("Invalid state parameter", [], null, 400);
            }

            $userId = $stateData['user_id'];

            // Verify the user making the request matches the state
            if ($request->auth && $request->auth->id != $userId) {
                return $this->failResponse("User mismatch", [], null, 403);
            }

            // Exchange code for tokens
            $token = $this->getOAuthService()->exchangeCodeForTokens($code, $userId);

            if (!$token) {
                return $this->failResponse("Failed to exchange authorization code", [], null, 400);
            }

            // Create default notification settings if not exists
            $parentId = $request->auth->parent_id ?? 0;
            if ($parentId > 0) {
                GmailNotificationSetting::getOrCreateForUser($userId, "mysql_{$parentId}");
            }

            // Auto-enable Gmail Watch for real-time push notifications
            $watchData = null;
            try {
                $watchData = $this->getOAuthService()->setupGmailWatch($userId);
                \Illuminate\Support\Facades\Log::info('Gmail Watch auto-enabled', [
                    'user_id' => $userId,
                    'email' => $token->gmail_email,
                    'history_id' => $watchData['historyId'] ?? null,
                ]);
            } catch (\Exception $watchException) {
                // Log but don't fail the connection if watch setup fails
                \Illuminate\Support\Facades\Log::warning('Gmail Watch auto-enable failed', [
                    'user_id' => $userId,
                    'error' => $watchException->getMessage(),
                ]);
            }

            return $this->successResponse("Gmail connected successfully", [
                'email' => $token->gmail_email,
                'connected' => true,
                'watch_enabled' => $watchData !== null,
                'watch_expiration' => isset($watchData['expiration'])
                    ? \Illuminate\Support\Carbon::createFromTimestampMs($watchData['expiration'])->toIso8601String()
                    : null,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("OAuth callback failed", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get Gmail connection status.
     */
    public function status(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $status = $this->getOAuthService()->getConnectionStatus($userId);

            return $this->successResponse("Connection status retrieved", $status);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get connection status", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Disconnect Gmail (revoke access).
     */
    public function disconnect(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            // Stop Gmail Watch first
            try {
                $this->getOAuthService()->stopGmailWatch($userId);
            } catch (\Exception $e) {
                // Log but continue with disconnect
                \Illuminate\Support\Facades\Log::warning('Failed to stop Gmail Watch on disconnect', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Revoke OAuth access
            $this->getOAuthService()->revokeAccess($userId);

            // Disable notifications
            $settings = GmailNotificationSetting::on("mysql_{$parentId}")
                ->where('user_id', $userId)
                ->first();

            if ($settings) {
                $settings->is_enabled = false;
                $settings->save();
            }

            return $this->successResponse("Gmail disconnected successfully", [
                'connected' => false,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to disconnect Gmail", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Manually refresh the access token.
     */
    public function refreshToken(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $token = GmailOAuthToken::getActiveForUser($userId);
            if (!$token) {
                return $this->failResponse("No active Gmail connection", [], null, 404);
            }

            $refreshedToken = $this->getOAuthService()->refreshAccessToken($token);

            if (!$refreshedToken) {
                return $this->failResponse("Failed to refresh token. Please reconnect Gmail.", [], null, 400);
            }

            return $this->successResponse("Token refreshed successfully", [
                'token_valid' => true,
                'expires_at' => $refreshedToken->token_expires_at->toIso8601String(),
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to refresh token", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Set up Gmail push notifications (watch).
     */
    public function setupWatch(Request $request)
    {
        try {
            $userId = $request->auth->id;

            // Check if Gmail is connected
            if (!$this->getOAuthService()->hasActiveConnection($userId)) {
                return $this->failResponse("Gmail is not connected. Please connect Gmail first.", [], null, 400);
            }

            $watchData = $this->getOAuthService()->setupGmailWatch($userId);

            if (!$watchData) {
                return $this->failResponse("Failed to set up Gmail push notifications", [], null, 500);
            }

            return $this->successResponse("Gmail push notifications enabled", [
                'watch_active' => true,
                'history_id' => $watchData['historyId'] ?? null,
                'expiration' => isset($watchData['expiration'])
                    ? \Illuminate\Support\Carbon::createFromTimestampMs($watchData['expiration'])->toIso8601String()
                    : null,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to set up watch", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Stop Gmail push notifications (watch).
     */
    public function stopWatch(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $this->getOAuthService()->stopGmailWatch($userId);

            return $this->successResponse("Gmail push notifications disabled", [
                'watch_active' => false,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to stop watch", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get Gmail watch status.
     */
    public function watchStatus(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $token = GmailOAuthToken::getActiveForUser($userId);

            if (!$token) {
                return $this->successResponse("Watch status", [
                    'connected' => false,
                    'watch_active' => false,
                ]);
            }

            $isExpiringSoon = $this->getOAuthService()->isWatchExpiringSoon($userId);

            return $this->successResponse("Watch status", [
                'connected' => true,
                'watch_active' => $token->watch_expiration !== null,
                'watch_expiration' => $token->watch_expiration?->toIso8601String(),
                'expiring_soon' => $isExpiringSoon,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get watch status", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Handle OAuth callback from Google (no auth required).
     * User info is retrieved from the state parameter.
     * Redirects to frontend portal with status.
     */
    public function callbackNoAuth(Request $request)
    {
        $frontendUrl = 'https://dial.linkswitchcommunications.com/gmail-settings';

        // Check for error from Google
        if ($request->has('error')) {
            $error = $request->input('error');
            $errorDesc = $request->input('error_description', 'Authorization denied');
            return redirect($frontendUrl . '?status=error&message=' . urlencode($errorDesc));
        }

        $code = $request->input('code');
        $state = $request->input('state');

        if (!$code || !$state) {
            return redirect($frontendUrl . '?status=error&message=' . urlencode('Missing code or state parameter'));
        }

        try {
            // Parse and validate state
            $stateData = $this->getOAuthService()->parseState($state);
            if (!$stateData) {
                return redirect($frontendUrl . '?status=error&message=' . urlencode('Invalid state parameter'));
            }

            $userId = $stateData['user_id'];

            // Get the user to find parent_id
            $user = \App\Model\User::find($userId);
            if (!$user) {
                return redirect($frontendUrl . '?status=error&message=' . urlencode('User not found'));
            }

            // Exchange code for tokens
            $token = $this->getOAuthService()->exchangeCodeForTokens($code, $userId);

            if (!$token) {
                return redirect($frontendUrl . '?status=error&message=' . urlencode('Failed to exchange authorization code'));
            }

            // Create default notification settings if not exists
            $parentId = $user->parent_id ?? 0;
            if ($parentId > 0) {
                GmailNotificationSetting::getOrCreateForUser($userId, "mysql_{$parentId}");
            }

            // Auto-enable Gmail Watch for real-time push notifications
            $watchData = null;
            try {
                $watchData = $this->getOAuthService()->setupGmailWatch($userId);
                \Illuminate\Support\Facades\Log::info('Gmail Watch auto-enabled', [
                    'user_id' => $userId,
                    'email' => $token->gmail_email,
                    'history_id' => $watchData['historyId'] ?? null,
                ]);
            } catch (\Exception $watchException) {
                // Log but don't fail the connection if watch setup fails
                \Illuminate\Support\Facades\Log::warning('Gmail Watch auto-enable failed', [
                    'user_id' => $userId,
                    'error' => $watchException->getMessage(),
                ]);
            }

            // Redirect to frontend with success
            $params = http_build_query([
                'status' => 'success',
                'email' => $token->gmail_email,
                'watch_enabled' => $watchData !== null ? '1' : '0',
            ]);

            return redirect($frontendUrl . '?' . $params);

        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Gmail OAuth callback failed', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return redirect($frontendUrl . '?status=error&message=' . urlencode('Connection failed: ' . $exception->getMessage()));
        }
    }
}
