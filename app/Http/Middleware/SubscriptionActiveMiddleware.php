<?php

namespace App\Http\Middleware;

use App\Services\PlanService;
use App\Services\RoutePermissionService;
use Closure;
use Illuminate\Http\Request;

/**
 * Ensure the client's subscription is in an active state.
 *
 * Returns 402 Payment Required when the subscription is expired or cancelled.
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

        if (!PlanService::isSubscriptionActive($clientId)) {
            return response()->json([
                'success' => false,
                'message' => 'Your subscription has expired. Please renew to continue using the platform.',
                'code'    => 'SUBSCRIPTION_INACTIVE',
                'data'    => [],
            ], 402);
        }

        return $next($request);
    }
}
