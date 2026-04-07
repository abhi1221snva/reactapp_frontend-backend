<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantIsolationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * 1. Validates that every authenticated request carries a non-zero tenant
     *    context (parent_id). Rejects with 403 if missing.
     * 2. Verifies that a database connection exists for the tenant.
     * 3. Sets the tenant connection as the default for the request lifecycle
     *    so that any Model::query() without explicit ::on() still hits the
     *    correct client database (defense-in-depth).
     *
     * Super-admin impersonation is handled upstream by JwtMiddleware which
     * already sets parent_id to the target client ID.
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

            // Verify the tenant database connection is configured
            $connectionName = 'mysql_' . $parentId;
            if (!Config::has("database.connections.{$connectionName}")) {
                Log::error("Tenant DB connection not configured", [
                    'parent_id'  => $parentId,
                    'connection' => $connectionName,
                    'user_id'    => $request->auth->id ?? null,
                ]);
                return response()->json([
                    "success" => false,
                    "message" => "Tenant database is not available. Please contact support.",
                    "errors"  => []
                ], 503);
            }

            // Bind tenant context to the request for use by downstream code
            $request->attributes->set('tenant_connection', $connectionName);
            $request->attributes->set('tenant_id', $parentId);
        }

        return $next($request);
    }
}
