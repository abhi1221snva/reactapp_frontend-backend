<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\TenantFlag;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Per-tenant RVM v2 pipeline mode resolver + cache.
 *
 * Safe defaults by construction:
 *   1. Global kill switch `rvm.use_new_pipeline` off → always 'legacy'
 *      (lets ops fully disable shadow/new pipeline during an incident by
 *      flipping a single env var, regardless of tenant-level config).
 *   2. Redis miss + DB miss → 'legacy'
 *   3. Any exception (Redis down, DB down, bad row) → 'legacy' + Log::warning
 *
 * Cache key: `rvm:tenant_mode:{client_id}`  (TTL 60s)
 */
class RvmFeatureFlagService
{
    private const CACHE_PREFIX = 'rvm:tenant_mode:';
    private const CACHE_TTL    = 60;

    /**
     * Resolve the active pipeline mode for a tenant.
     * Returns one of: 'legacy', 'shadow', 'dry_run', 'live'.
     */
    public function modeForTenant(int $clientId): string
    {
        // Global kill switch — when off, nothing RVM-v2 runs.
        if (!config('rvm.use_new_pipeline', false)) {
            return TenantFlag::MODE_LEGACY;
        }

        try {
            $key = self::CACHE_PREFIX . $clientId;
            $cached = Redis::get($key);
            if ($cached !== null && in_array($cached, TenantFlag::ALL_MODES, true)) {
                return $cached;
            }

            $flag = TenantFlag::on('master')->find($clientId);
            $mode = ($flag && in_array($flag->pipeline_mode, TenantFlag::ALL_MODES, true))
                ? $flag->pipeline_mode
                : TenantFlag::MODE_LEGACY;

            Redis::setex($key, self::CACHE_TTL, $mode);
            return $mode;
        } catch (Throwable $e) {
            Log::warning('RvmFeatureFlagService: flag lookup failed, defaulting to legacy', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            return TenantFlag::MODE_LEGACY;
        }
    }

    /**
     * Set (upsert) a tenant's mode and invalidate the cache atomically.
     * Callers are responsible for authorization — this method trusts the caller.
     *
     * Live-column handling ($touchLiveColumns=true):
     *   - writes live_provider + live_daily_cap from the passed arguments
     *     (NULL clears the columns).
     *   - stamps live_enabled_at = now() iff the tenant is transitioning
     *     *into* live from a non-live state. Subsequent live→live writes
     *     preserve the original enabled_at so operators can tell when the
     *     tenant first went live.
     *
     * When $touchLiveColumns=false (default) the live_* columns are not
     * touched — preserves the existing kill-switch / rollback-all path
     * that only cares about pipeline_mode.
     */
    public function setTenantMode(
        int $clientId,
        string $mode,
        ?int $enabledByUserId = null,
        ?string $notes = null,
        ?string $liveProvider = null,
        ?int $liveDailyCap = null,
        bool $touchLiveColumns = false,
    ): TenantFlag {
        if (!in_array($mode, TenantFlag::ALL_MODES, true)) {
            throw new \InvalidArgumentException("Invalid pipeline mode: {$mode}");
        }

        /** @var TenantFlag $flag */
        $flag = TenantFlag::on('master')->firstOrNew(['client_id' => $clientId]);
        $wasLive = $flag->exists && $flag->pipeline_mode === TenantFlag::MODE_LIVE;

        $flag->client_id          = $clientId;
        $flag->pipeline_mode      = $mode;
        $flag->enabled_by_user_id = $enabledByUserId;
        $flag->notes              = $notes;

        if ($touchLiveColumns) {
            $flag->live_provider  = $liveProvider;
            $flag->live_daily_cap = $liveDailyCap;

            if ($mode === TenantFlag::MODE_LIVE && !$wasLive) {
                $flag->live_enabled_at = now();
            }
            // Intentionally NOT clearing live_enabled_at on downgrade —
            // it's an audit stamp of "was once live", not a reversible flag.
        }

        $flag->save();

        $this->flushTenant($clientId);
        return $flag;
    }

    /**
     * Drop the cache entry for a single tenant — safe to call on any error.
     */
    public function flushTenant(int $clientId): void
    {
        try {
            Redis::del(self::CACHE_PREFIX . $clientId);
        } catch (Throwable $e) {
            Log::warning('RvmFeatureFlagService: cache flush failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
