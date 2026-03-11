<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarOAuthService;
use Illuminate\Http\Request;

class GoogleCalendarOAuthController extends Controller
{
    protected ?GoogleCalendarOAuthService $service = null;

    protected function getService(): GoogleCalendarOAuthService
    {
        if (!$this->service) {
            $this->service = new GoogleCalendarOAuthService();
        }
        return $this->service;
    }

    /**
     * Handle OAuth callback from Google (no auth required).
     * User info is retrieved from the state parameter.
     * Redirects to the frontend profile page with status.
     */
    public function callbackNoAuth(Request $request)
    {
        $frontendUrl = 'https://dial.linkswitchcommunications.com/profile';

        if ($request->has('error')) {
            $errorDesc = $request->input('error_description', 'Authorization denied');
            return redirect($frontendUrl . '?integration=google_calendar&status=error&message=' . urlencode($errorDesc));
        }

        $code  = $request->input('code');
        $state = $request->input('state');

        if (!$code || !$state) {
            return redirect($frontendUrl . '?integration=google_calendar&status=error&message=' . urlencode('Missing code or state parameter'));
        }

        try {
            $stateData = $this->getService()->parseState($state);
            if (!$stateData) {
                return redirect($frontendUrl . '?integration=google_calendar&status=error&message=' . urlencode('Invalid state parameter'));
            }

            $userId = $stateData['user_id'];

            $user = \App\Model\User::find($userId);
            if (!$user) {
                return redirect($frontendUrl . '?integration=google_calendar&status=error&message=' . urlencode('User not found'));
            }

            $token = $this->getService()->exchangeCodeForTokens($code, $userId);

            if (!$token) {
                return redirect($frontendUrl . '?integration=google_calendar&status=error&message=' . urlencode('Failed to exchange authorization code'));
            }

            $params = http_build_query([
                'integration' => 'google_calendar',
                'status'      => 'success',
                'email'       => $token->calendar_email,
            ]);

            return redirect($frontendUrl . '?' . $params);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Google Calendar OAuth callback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect($frontendUrl . '?integration=google_calendar&status=error&message=' . urlencode('Connection failed: ' . $e->getMessage()));
        }
    }
}
