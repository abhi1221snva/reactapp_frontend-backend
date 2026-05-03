<?php

namespace App\Services;

use App\Model\Master\Client;
use App\Model\Master\ClientUsageMonthly;
use App\Model\Master\SubscriptionPlan;
use App\Model\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PlanService
 *
 * Centralised subscription plan enforcement for the Rocket Dialer platform.
 *
 * Every check method returns permissive defaults when a client has no plan
 * assigned, ensuring backward compatibility with existing tenants.
 *
 * Usage counters (calls, SMS) are tracked via Redis atomic increments and
 * batch-persisted to the `client_usage_monthly` table for durability.
 */
class PlanService
{
    // ── Redis key prefixes ─────────────────────────────────────────────────
    const REDIS_CALLS_PREFIX = 'plan_usage:calls';
    const REDIS_SMS_PREFIX   = 'plan_usage:sms';

    // ── Cache keys / TTLs ──────────────────────────────────────────────────
    const CACHE_KEY_PLAN = 'subscription_plan';
    const PLAN_CACHE_TTL = 3600; // 1 hour

    // ── Batch persist interval ─────────────────────────────────────────────
    const CALLS_PERSIST_EVERY = 100;
    const SMS_PERSIST_EVERY   = 50;

    // ── Redis TTL for counters (auto-cleanup after month ends) ─────────────
    const COUNTER_TTL_SECONDS = 3024000; // 35 days

    // ═══════════════════════════════════════════════════════════════════════
    //  Plan retrieval
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get the subscription plan and client subscription data (cached).
     *
     * @return array|null  ['plan' => [...], 'client' => [...]] or null
     */
    public static function getClientPlan(int $clientId): ?array
    {
        return CacheService::tenantRemember(
            $clientId,
            self::CACHE_KEY_PLAN,
            self::PLAN_CACHE_TTL,
            function () use ($clientId) {
                $client = Client::find($clientId);
                if (!$client || !$client->subscription_plan_id) {
                    return null;
                }

                $plan = SubscriptionPlan::find($client->subscription_plan_id);
                if (!$plan) {
                    return null;
                }

                return [
                    'plan' => $plan->toArray(),
                    'client' => [
                        'billing_cycle'            => $client->billing_cycle,
                        'subscription_status'      => $client->subscription_status,
                        'subscription_started_at'  => $client->subscription_started_at,
                        'subscription_ends_at'     => $client->subscription_ends_at,
                        'custom_max_agents'        => $client->custom_max_agents,
                        'custom_max_calls_monthly' => $client->custom_max_calls_monthly,
                        'custom_max_sms_monthly'   => $client->custom_max_sms_monthly,
                        'seat_quantity'            => $client->seat_quantity ?? 1,
                    ],
                ];
            }
        );
    }

    /**
     * Resolve the effective limit for a given key.
     *
     * For per-seat billing, max_agents is driven by client.seat_quantity.
     * Checks custom_* override first, then falls back to the plan default.
     * Returns 0 for "unlimited" or when no plan is assigned.
     */
    public static function getEffectiveLimit(int $clientId, string $limitKey): int
    {
        $data = self::getClientPlan($clientId);
        if (!$data) {
            return 0; // no plan = no limit (backward compat)
        }

        // Per-seat billing: max_agents is determined by seat_quantity
        if ($limitKey === 'max_agents') {
            $customVal = $data['client']['custom_max_agents'] ?? null;
            if ($customVal !== null) {
                return (int) $customVal;
            }
            return (int) ($data['client']['seat_quantity'] ?? 1);
        }

        $customKey = "custom_{$limitKey}";
        $customVal = $data['client'][$customKey] ?? null;
        if ($customVal !== null) {
            return (int) $customVal;
        }

        return (int) ($data['plan'][$limitKey] ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Seat limit
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if adding an agent would exceed the seat limit.
     *
     * Per-seat billing: the limit is driven by client.seat_quantity
     * (purchased seats), not plan.max_agents.
     *
     * @return array{allowed: bool, current: int, max: int}
     */
    public static function checkSeatLimit(int $clientId): array
    {
        $max = self::getEffectiveLimit($clientId, 'max_agents');
        if ($max === 0) {
            return ['allowed' => true, 'current' => 0, 'max' => 0]; // unlimited
        }

        $current = User::where('parent_id', $clientId)
            ->where('is_deleted', 0)
            ->where('status', 1)
            ->count();

        return [
            'allowed' => $current < $max,
            'current' => $current,
            'max'     => $max,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Call limit
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if making a call would exceed the monthly call limit.
     *
     * @return array{allowed: bool, current: int, max: int}
     */
    public static function checkCallLimit(int $clientId): array
    {
        $max = self::getEffectiveLimit($clientId, 'max_calls_monthly');
        if ($max === 0) {
            return ['allowed' => true, 'current' => 0, 'max' => 0]; // unlimited
        }

        $current = (int) Cache::get(self::callRedisKey($clientId), 0);

        return [
            'allowed' => $current < $max,
            'current' => $current,
            'max'     => $max,
        ];
    }

    /**
     * Increment the call usage counter after a successful call.
     */
    public static function incrementCallUsage(int $clientId): void
    {
        $key = self::callRedisKey($clientId);

        try {
            $newVal = Cache::increment($key);

            // Set expiry on first increment of the month
            if ($newVal === 1) {
                Cache::put($key, 1, self::COUNTER_TTL_SECONDS);
            }

            // Batch-persist to DB to avoid a write on every single call
            if ($newVal % self::CALLS_PERSIST_EVERY === 0) {
                self::persistCallCount($clientId, $newVal);
            }
        } catch (\Throwable $e) {
            Log::warning('PlanService: call usage increment failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  SMS limit
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if sending SMS would exceed the monthly limit.
     *
     * @return array{allowed: bool, current: int, max: int}
     */
    public static function checkSmsLimit(int $clientId): array
    {
        $max = self::getEffectiveLimit($clientId, 'max_sms_monthly');
        if ($max === 0) {
            return ['allowed' => true, 'current' => 0, 'max' => 0];
        }

        $current = (int) Cache::get(self::smsRedisKey($clientId), 0);

        return [
            'allowed' => $current < $max,
            'current' => $current,
            'max'     => $max,
        ];
    }

    /**
     * Increment the SMS usage counter.
     */
    public static function incrementSmsUsage(int $clientId, int $count = 1): void
    {
        $key = self::smsRedisKey($clientId);

        try {
            $newVal = Cache::increment($key, $count);

            if ($newVal === $count) {
                Cache::put($key, $count, self::COUNTER_TTL_SECONDS);
            }

            if ($newVal % self::SMS_PERSIST_EVERY === 0) {
                self::persistSmsCount($clientId, $newVal);
            }
        } catch (\Throwable $e) {
            Log::warning('PlanService: SMS usage increment failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Feature checks
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if the client's plan includes a specific feature.
     */
    public static function hasFeature(int $clientId, string $featureKey): bool
    {
        if (!isset(SubscriptionPlan::FEATURE_MAP[$featureKey])) {
            return false;
        }

        $data = self::getClientPlan($clientId);
        if (!$data) {
            return true; // no plan = allowed (backward compat)
        }

        $plan = SubscriptionPlan::find($data['plan']['id'] ?? 0);
        if (!$plan) {
            return true;
        }

        return $plan->hasFeature($featureKey);
    }

    /**
     * Get all feature flags for the client's current plan.
     *
     * @return array<string, bool>
     */
    public static function getAllFeatures(int $clientId): array
    {
        $data = self::getClientPlan($clientId);
        if (!$data) {
            return array_fill_keys(array_keys(SubscriptionPlan::FEATURE_MAP), true);
        }

        $plan = SubscriptionPlan::find($data['plan']['id'] ?? 0);
        if (!$plan) {
            return array_fill_keys(array_keys(SubscriptionPlan::FEATURE_MAP), true);
        }

        return $plan->featureFlags();
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Subscription status
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if the client's subscription is in an active state.
     */
    public static function isSubscriptionActive(int $clientId): bool
    {
        $data = self::getClientPlan($clientId);
        if (!$data) {
            return true; // no plan = allowed (backward compat)
        }

        $status = $data['client']['subscription_status'] ?? 'active';
        return in_array($status, ['active', 'trial'], true);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Usage summary
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get a full usage summary for the current billing period.
     */
    public static function getUsageSummary(int $clientId): array
    {
        $seatCheck = self::checkSeatLimit($clientId);
        $callCheck = self::checkCallLimit($clientId);
        $smsCheck  = self::checkSmsLimit($clientId);

        $data = self::getClientPlan($clientId);

        return [
            'agents'        => ['current' => $seatCheck['current'], 'max' => $seatCheck['max']],
            'calls'         => ['current' => $callCheck['current'], 'max' => $callCheck['max']],
            'sms'           => ['current' => $smsCheck['current'],  'max' => $smsCheck['max']],
            'seat_quantity' => $data ? (int) ($data['client']['seat_quantity'] ?? 1) : 1,
            'year_month'    => Carbon::now()->format('Y-m'),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Cache invalidation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Invalidate the cached plan for a client (call after plan changes).
     */
    public static function invalidateClientPlan(int $clientId): void
    {
        CacheService::tenantForget($clientId, self::CACHE_KEY_PLAN);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Backward compatibility
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Sync plan feature flags to the legacy clients columns.
     *
     * Some legacy code checks clients.predictive_dial, clients.mca_crm, etc.
     * This method writes the plan booleans to those columns so old code works.
     */
    public static function syncFeatureFlagsToClient(int $clientId): void
    {
        $data = self::getClientPlan($clientId);
        if (!$data) {
            return;
        }

        $plan = $data['plan'];

        Client::where('id', $clientId)->update([
            'predictive_dial' => ($plan['has_predictive_dialer'] ?? false) ? '1' : '0',
            'mca_crm'         => ($plan['has_full_crm'] ?? false) ? 1 : 0,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Persistence helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Persist current call count to the database.
     */
    public static function persistCallCount(int $clientId, int $count): void
    {
        try {
            ClientUsageMonthly::updateOrCreate(
                ['client_id' => $clientId, 'year_month' => Carbon::now()->format('Y-m')],
                ['calls_count' => $count]
            );
        } catch (\Throwable $e) {
            Log::warning('PlanService: call count persist failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist current SMS count to the database.
     */
    public static function persistSmsCount(int $clientId, int $count): void
    {
        try {
            ClientUsageMonthly::updateOrCreate(
                ['client_id' => $clientId, 'year_month' => Carbon::now()->format('Y-m')],
                ['sms_count' => $count]
            );
        } catch (\Throwable $e) {
            Log::warning('PlanService: SMS count persist failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Internal helpers
    // ═══════════════════════════════════════════════════════════════════════

    private static function callRedisKey(int $clientId): string
    {
        return self::REDIS_CALLS_PREFIX . ":{$clientId}:" . Carbon::now()->format('Y-m');
    }

    private static function smsRedisKey(int $clientId): string
    {
        return self::REDIS_SMS_PREFIX . ":{$clientId}:" . Carbon::now()->format('Y-m');
    }
}
