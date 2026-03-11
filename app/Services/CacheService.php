<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * CacheService
 *
 * Centralised, tenant-aware caching helpers for the Rocket Dialer backend.
 *
 * Cache key anatomy:
 *   tenant:{clientId}:{key}  - per-tenant data (most common)
 *   global:{key}             - platform-wide data (admin lists, etc.)
 *
 * TTL constants follow short -> medium -> long -> day so callers can pick
 * the right staleness budget without magic numbers scattered in controllers.
 */
class CacheService
{
    // ── TTL constants ─────────────────────────────────────────────────────────

    /** Near-real-time data (callbacks, active calls) */
    const TTL_SHORT  = 60;

    /** Dashboard counts, campaign lists */
    const TTL_MEDIUM = 300;

    /** Static lookups (roles, timezones, countries) */
    const TTL_LONG   = 3600;

    /** Very stable data (client metadata) */
    const TTL_DAY    = 86400;

    // ── Known per-tenant keys (used for targeted invalidation) ───────────────

    const KEY_DASHBOARD_STATS = 'dashboard_stats';
    const KEY_AGENTS_LIST     = 'agents_list';
    const KEY_CAMPAIGNS_LIST  = 'campaigns_list';
    const KEY_DIDS_COUNT      = 'dids_count';
    const KEY_LEADS_COUNT     = 'leads_count';
    const KEY_LISTS_COUNT     = 'lists_count';
    const KEY_CAMPAIGNS_COUNT = 'campaigns_count';
    const KEY_USERS_COUNT     = 'users_count';

    // ── Tenant-scoped helpers ─────────────────────────────────────────────────

    /**
     * Cache a value scoped to a specific tenant (client_id).
     *
     * Usage:
     *   $data = CacheService::tenantRemember(
     *       $clientId,
     *       CacheService::KEY_CAMPAIGNS_LIST,
     *       CacheService::TTL_MEDIUM,
     *       fn () => Campaign::on("mysql_{$clientId}")->get()
     *   );
     */
    public static function tenantRemember(int $clientId, string $key, int $ttl, \Closure $callback): mixed
    {
        return Cache::remember(self::tenantKey($clientId, $key), $ttl, $callback);
    }

    /**
     * Forget a single tenant-scoped key.
     */
    public static function tenantForget(int $clientId, string $key): void
    {
        Cache::forget(self::tenantKey($clientId, $key));
    }

    /**
     * Flush all well-known cache keys for a tenant.
     *
     * Called on any mutation (create / update / delete) that affects cached
     * data. Tag-based flush is attempted first (requires Redis); falls back to
     * explicit key deletion so file/array drivers continue to work.
     */
    public static function flushTenant(int $clientId): void
    {
        try {
            Cache::tags(["tenant:{$clientId}"])->flush();
        } catch (\BadMethodCallException | \Exception $e) {
            // Driver does not support tags - delete known keys individually.
            $knownKeys = [
                self::KEY_DASHBOARD_STATS,
                self::KEY_AGENTS_LIST,
                self::KEY_CAMPAIGNS_LIST,
                self::KEY_DIDS_COUNT,
                self::KEY_LEADS_COUNT,
                self::KEY_LISTS_COUNT,
                self::KEY_CAMPAIGNS_COUNT,
                self::KEY_USERS_COUNT,
            ];

            foreach ($knownKeys as $key) {
                Cache::forget(self::tenantKey($clientId, $key));
            }
        }
    }

    // ── Global (non-tenant) helpers ───────────────────────────────────────────

    /**
     * Cache a global (non-tenant-scoped) value.
     */
    public static function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        return Cache::remember("global:{$key}", $ttl, $callback);
    }

    /**
     * Forget a global key.
     */
    public static function forget(string $key): void
    {
        Cache::forget("global:{$key}");
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private static function tenantKey(int $clientId, string $key): string
    {
        return "tenant:{$clientId}:{$key}";
    }
}
