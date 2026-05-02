<?php

namespace App\Http\Middleware;

use App\Services\PlanService;
use App\Services\RoutePermissionService;
use Closure;
use Illuminate\Http\Request;

/**
 * Gate routes by subscription plan feature flags.
 *
 * Usage in routes: 'plan.feature:predictive_dialer'
 */
class PlanFeatureMiddleware
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        // System admins bypass all plan checks
        if (($request->auth->level ?? 0) >= RoutePermissionService::SYSTEM_ADMIN_LEVEL) {
            return $next($request);
        }

        $clientId = (int) ($request->auth->parent_id ?? 0);
        if (!$clientId) {
            return $next($request); // safety fallback
        }

        if (!PlanService::hasFeature($clientId, $feature)) {
            return response()->json([
                'success' => false,
                'message' => "This feature requires a plan upgrade. Your current plan does not include: {$feature}.",
                'code'    => 'PLAN_FEATURE_UNAVAILABLE',
                'data'    => ['feature' => $feature],
            ], 403);
        }

        return $next($request);
    }
}
