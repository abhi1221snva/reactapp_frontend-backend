<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * TenantAware trait
 *
 * Provides helpers for multi-tenant isolation across controllers.
 *
 * Architecture notes:
 *   - Each tenant has its own MySQL database identified as "mysql_<parent_id>".
 *   - $request->auth->parent_id is the canonical tenant identifier for both
 *     regular users and impersonating admins (JwtMiddleware already overwrites
 *     parent_id with the target client ID when an admin is impersonating).
 *   - Tenant isolation is enforced at the DB-connection level via ->on(...)
 *     or DB::connection(...). This trait does NOT add row-level WHERE clauses
 *     unless the model is on the master DB and uses a client_id / parent_id
 *     column for cross-tenant filtering.
 */
trait TenantAware
{
    // -------------------------------------------------------------------------
    // Core helpers
    // -------------------------------------------------------------------------

    /**
     * Return the authenticated tenant's client ID (integer).
     *
     * Returns the value from $request->auth->parent_id, cast to int.
     * Returns 0 when there is no authenticated user (should not happen on
     * protected routes, but avoids null-related type errors).
     */
    protected function tenantId(Request $request): int
    {
        return (int) ($request->auth->parent_id ?? 0);
    }

    /**
     * Return the Eloquent / DB connection name for the current tenant.
     *
     * Usage:
     *   Campaign::on($this->tenantDb($request))->where(...)->get();
     *   DB::connection($this->tenantDb($request))->table('campaign')->get();
     */
    protected function tenantDb(Request $request): string
    {
        return 'mysql_' . $this->tenantId($request);
    }

    // -------------------------------------------------------------------------
    // Ownership assertion (for models that carry a tenant FK on the master DB)
    // -------------------------------------------------------------------------

    /**
     * Assert that the given model belongs to the current tenant.
     *
     * Use this only for models stored in the master database that carry a
     * client_id or parent_id foreign key. For models stored in per-tenant
     * databases (the common case), the DB connection already provides
     * isolation — no row-level check is needed.
     *
     * Returns a 403 JsonResponse when the ownership check fails, or null
     * when everything is fine.
     *
     * Example:
     *   $campaign = Campaign::on($this->tenantDb($request))->find($id);
     *   if ($err = $this->assertTenantOwns($campaign, $request)) return $err;
     */
    protected function assertTenantOwns(mixed $model, Request $request): ?JsonResponse
    {
        if (!$model) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
                'data'    => [],
            ], 404);
        }

        $tenantId = $this->tenantId($request);

        // Support models with either parent_id or client_id as the tenant FK.
        $modelTenantId = $model->parent_id ?? $model->client_id ?? null;

        if ($tenantId === 0 || $modelTenantId === null || (int) $modelTenantId !== $tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Resource does not belong to your account.',
                'data'    => [],
            ], 403);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Query scope helper (for master-DB queries with a tenant FK column)
    // -------------------------------------------------------------------------

    /**
     * Scope an Eloquent or query-builder instance to the current tenant.
     *
     * Use this only when querying the master database and the table has a
     * client_id / parent_id column. For per-tenant DB connections this is
     * unnecessary because ->on('mysql_<id>') already scopes everything.
     *
     * Usage:
     *   $this->tenantScope(
     *       DB::connection('master')->table('some_table'),
     *       $request,
     *       'client_id'
     *   )->get();
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $column  Defaults to 'parent_id'; pass 'client_id' when needed.
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    protected function tenantScope(
        \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query,
        Request $request,
        string $column = 'parent_id'
    ): mixed {
        return $query->where($column, $this->tenantId($request));
    }
}
