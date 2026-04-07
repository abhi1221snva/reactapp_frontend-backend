<?php

namespace App\Http\Middleware;

use App\Services\RoutePermissionService;
use Closure;
use Illuminate\Http\Request;

class CheckRouteAccess
{
    public function handle(Request $request, Closure $next)
    {
        // System administrator (level 11) bypasses ALL permission checks
        if (($request->auth->level ?? 0) >= RoutePermissionService::SYSTEM_ADMIN_LEVEL) {
            return $next($request);
        }

        $service = app(RoutePermissionService::class);
        $routeGroupKey = $service->getRouteGroupForPath($request->getPathInfo());

        // If no route group is mapped for this path, allow through (backward compat)
        if ($routeGroupKey === null) {
            return $next($request);
        }

        $roleId = $request->auth->role ?? null;

        if (!$roleId || !$service->roleHasRouteAccess((int) $roleId, $routeGroupKey)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this resource.',
                'data' => [],
            ], 403);
        }

        return $next($request);
    }
}
