<?php

namespace App\Http\Controllers;

use App\Model\Master\GmailOAuthToken;
use App\Model\Master\GoogleCalendarToken;
use App\Services\GmailOAuthService;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Http\Request;

/**
 * @OA\Get(
 *   path="/integrations",
 *   summary="Get OAuth integration status for all providers",
 *   operationId="integrationsIndex",
 *   tags={"Integrations"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Integration statuses for gmail and google_calendar"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/connect-integration",
 *   summary="Get OAuth authorization URL for a provider",
 *   operationId="integrationsConnect",
 *   tags={"Integrations"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"provider"},
 *     @OA\Property(property="provider", type="string", enum={"gmail","google_calendar"})
 *   )),
 *   @OA\Response(response=200, description="Authorization URL to redirect user to")
 * )
 *
 * @OA\Post(
 *   path="/disconnect-integration",
 *   summary="Revoke OAuth access for a provider",
 *   operationId="integrationsDisconnect",
 *   tags={"Integrations"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"provider"},
 *     @OA\Property(property="provider", type="string", enum={"gmail","google_calendar"})
 *   )),
 *   @OA\Response(response=200, description="Integration disconnected")
 * )
 */
class IntegrationController extends Controller
{
    /**
     * GET /integrations
     * Returns connection status for all supported OAuth integrations.
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $gmailToken    = GmailOAuthToken::getActiveForUser($userId);
            $calendarToken = GoogleCalendarToken::getActiveForUser($userId);

            $integrations = [
                [
                    'provider'     => 'gmail',
                    'connected'    => $gmailToken !== null,
                    'account'      => $gmailToken?->gmail_email,
                    'connected_at' => $gmailToken?->created_at?->toIso8601String(),
                ],
                [
                    'provider'     => 'google_calendar',
                    'connected'    => $calendarToken !== null,
                    'account'      => $calendarToken?->calendar_email,
                    'connected_at' => $calendarToken?->created_at?->toIso8601String(),
                ],
            ];

            return $this->successResponse('Integrations retrieved', $integrations);

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to retrieve integrations', [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /connect-integration
     * Returns the OAuth redirect_url to send the user to Google.
     * Body: { provider: 'gmail' | 'google_calendar' }
     */
    public function connect(Request $request)
    {
        $this->validate($request, [
            'provider' => 'required|string|in:gmail,google_calendar',
        ]);

        try {
            $userId   = $request->auth->id;
            $provider = $request->input('provider');

            if ($provider === 'gmail') {
                $service      = new GmailOAuthService();
                $redirectUrl  = $service->getAuthorizationUrl($userId);
            } else {
                $service      = new GoogleCalendarOAuthService();
                $redirectUrl  = $service->getAuthorizationUrl($userId);
            }

            return $this->successResponse('Authorization URL generated', [
                'redirect_url' => $redirectUrl,
            ]);

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to generate authorization URL', [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /disconnect-integration
     * Revokes OAuth access for the given provider.
     * Body: { provider: 'gmail' | 'google_calendar' }
     */
    public function disconnect(Request $request)
    {
        $this->validate($request, [
            'provider' => 'required|string|in:gmail,google_calendar',
        ]);

        try {
            $userId   = $request->auth->id;
            $provider = $request->input('provider');

            if ($provider === 'gmail') {
                $service = new GmailOAuthService();

                // Stop Gmail Watch before revoking
                try {
                    $service->stopGmailWatch($userId);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to stop Gmail Watch on disconnect', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                }

                $service->revokeAccess($userId);

                // Disable notification settings if they exist
                $parentId = $request->auth->parent_id ?? 0;
                if ($parentId > 0) {
                    try {
                        $settings = \App\Model\Client\GmailNotificationSetting::on("mysql_{$parentId}")
                            ->where('user_id', $userId)
                            ->first();
                        if ($settings) {
                            $settings->is_enabled = false;
                            $settings->save();
                        }
                    } catch (\Exception $e) {
                        // Non-critical
                    }
                }
            } else {
                $service = new GoogleCalendarOAuthService();
                $service->revokeAccess($userId);
            }

            return $this->successResponse('Integration disconnected', ['connected' => false]);

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to disconnect integration', [$e->getMessage()], $e, 500);
        }
    }
}
