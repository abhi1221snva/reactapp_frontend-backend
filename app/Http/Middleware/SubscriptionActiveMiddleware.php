<?php

namespace App\Http\Middleware;

use App\Model\Master\Client;
use App\Services\PlanService;
use App\Services\RoutePermissionService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

/**
 * Ensure the client's subscription is in an active state.
 *
 * Returns 402 Payment Required when the subscription is expired or cancelled.
 *
 * Grace period behaviour:
 *   - During the 3-day grace period after expiry, GET (read-only) requests
 *     are allowed so the client can still view their data and billing pages.
 *   - Write operations (POST/PUT/DELETE) are blocked with a 402 response.
 *   - After grace ends, ALL requests are blocked.
 */
class SubscriptionActiveMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // System admins bypass
        if (($request->auth->level ?? 0) >= RoutePermissionService::SYSTEM_ADMIN_LEVEL) {
            return $next($request);
        }

        $clientId = (int) ($request->auth->parent_id ?? 0);
        if (!$clientId) {
            return $next($request);
        }

        // Active or trial — proceed normally
        if (PlanService::isSubscriptionActive($clientId)) {
            return $next($request);
        }

        // Subscription is not active — check for grace period
        $client = Client::find($clientId);

        if ($client
            && $client->grace_period_ends_at
            && Carbon::parse($client->grace_period_ends_at)->isFuture()
        ) {
            // Grace period: allow GET (read-only) access
            if ($request->isMethod('GET')) {
                $response = $next($request);

                // Add headers so frontend can show grace banner
                if (method_exists($response, 'header')) {
                    $response->header('X-Subscription-Grace', 'true');
                    $response->header('X-Grace-Ends-At', $client->grace_period_ends_at);
                }

                return $response;
            }

            // Block write operations during grace
            $graceEndsFormatted = Carbon::parse($client->grace_period_ends_at)->format('M j, Y');

            return response()->json([
                'success' => false,
                'message' => "Your subscription has expired. You have read-only access until {$graceEndsFormatted}. Please upgrade to continue.",
                'code'    => 'SUBSCRIPTION_GRACE',
                'data'    => [
                    'subscription_status' => $client->subscription_status,
                    'grace_ends_at'       => $client->grace_period_ends_at,
                    'action'              => 'upgrade_plan',
                ],
            ], 402);
        }

        // Fully expired — no grace period
        return response()->json([
            'success' => false,
            'message' => 'Your subscription has expired. Please renew to continue using the platform.',
            'code'    => 'SUBSCRIPTION_INACTIVE',
            'data'    => [
                'subscription_status' => $client->subscription_status ?? 'expired',
                'action'              => 'upgrade_plan',
            ],
        ], 402);
    }
}
