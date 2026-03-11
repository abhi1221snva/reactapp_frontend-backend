<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TenantIsolationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Validates that every authenticated request carries a non-zero tenant
     * context (parent_id). Rejects with 403 if an auth object is present but
     * parent_id is 0 or missing. Super-admin impersonation is handled upstream
     * by JwtMiddleware which already sets parent_id to the target client ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->auth !== null) {
            $parentId = (int) ($request->auth->parent_id ?? 0);

            if ($parentId === 0) {
                return response()->json([
                    "success" => false,
                    "message" => "Tenant context is missing. A valid client scope is required to access this resource.",
                    "errors"  => []
                ], 403);
            }
        }

        return $next($request);
    }
}
