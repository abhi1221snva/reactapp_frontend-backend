<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\User;

/**
 * Reusable tenant isolation helper.
 *
 * Usage:
 *   TenantHelper::connection($request)   → 'mysql_3'
 *   TenantHelper::id($request)           → 3
 *   TenantHelper::scopeUsers($request)   → Builder scoped to tenant
 *   TenantHelper::findTenantUser($id, $request) → User or null
 *   TenantHelper::db($request)           → DB connection instance
 */
class TenantHelper
{
    /**
     * Get the tenant's parent_id from the authenticated request.
     */
    public static function id(Request $request): int
    {
        return (int) ($request->auth->parent_id ?? 0);
    }

    /**
     * Get the tenant's database connection name: 'mysql_{parent_id}'.
     */
    public static function connection(Request $request): string
    {
        return 'mysql_' . static::id($request);
    }

    /**
     * Get a DB connection instance for the current tenant.
     */
    public static function db(Request $request)
    {
        return DB::connection(static::connection($request));
    }

    /**
     * Scope a query on master.users to only the current tenant.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public static function scopeUsers(Request $request)
    {
        return DB::connection('master')->table('users')
            ->where('parent_id', static::id($request))
            ->where('is_deleted', 0);
    }

    /**
     * Find a user by ID, but ONLY if they belong to the current tenant.
     * Returns null if not found or not owned by tenant.
     */
    public static function findTenantUser(int $userId, Request $request): ?User
    {
        return User::where('id', $userId)
            ->where('parent_id', static::id($request))
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * Find a user by ID with just a parent_id (no Request needed).
     * Useful in services/jobs where Request is not available.
     */
    public static function findUserByParent(int $userId, int $parentId): ?User
    {
        return User::where('id', $userId)
            ->where('parent_id', $parentId)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * Verify that a given user ID belongs to the tenant.
     * Returns true if ownership is confirmed, false otherwise.
     */
    public static function ownsUser(int $userId, Request $request): bool
    {
        return DB::connection('master')->table('users')
            ->where('id', $userId)
            ->where('parent_id', static::id($request))
            ->exists();
    }

    /**
     * Verify that a set of user IDs all belong to the tenant.
     * Returns the filtered list (only IDs belonging to tenant).
     */
    public static function filterTenantUserIds(array $userIds, Request $request): array
    {
        if (empty($userIds)) return [];

        return DB::connection('master')->table('users')
            ->whereIn('id', $userIds)
            ->where('parent_id', static::id($request))
            ->where('is_deleted', 0)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get connection name from a parent_id directly (no Request needed).
     * Useful in jobs, commands, and services.
     */
    public static function connectionFor(int $parentId): string
    {
        return 'mysql_' . $parentId;
    }
}
