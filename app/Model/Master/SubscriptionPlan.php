<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SubscriptionPlan extends Model
{
    protected $connection = 'master';
    protected $table = 'subscription_plans';

    const SLUG_STARTER    = 'starter';
    const SLUG_GROWTH     = 'growth';
    const SLUG_PRO        = 'pro';
    const SLUG_ENTERPRISE = 'enterprise';
    const SLUG_PER_SEAT   = 'per_seat';

    /**
     * Map feature keys (used in middleware/service calls) to database columns.
     */
    const FEATURE_MAP = [
        'predictive_dialer'   => 'has_predictive_dialer',
        'full_crm'            => 'has_full_crm',
        'api_access'          => 'has_api_access',
        'ai_coaching'         => 'has_ai_coaching',
        'custom_integrations' => 'has_custom_integrations',
        'sso'                 => 'has_sso',
        'dedicated_csm'       => 'has_dedicated_csm',
        'white_label'         => 'has_white_label',
        'on_premise'          => 'has_on_premise',
        'compliance_packages' => 'has_compliance_packages',
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_monthly',
        'price_annual',
        'unit_price_cents',
        'billing_model',
        'max_agents',
        'max_calls_monthly',
        'max_sms_monthly',
        'has_predictive_dialer',
        'has_full_crm',
        'has_api_access',
        'has_ai_coaching',
        'has_custom_integrations',
        'has_sso',
        'has_dedicated_csm',
        'has_white_label',
        'has_on_premise',
        'has_compliance_packages',
        'is_active',
        'display_order',
        'plan_order',
        'trial_days',
        'stripe_product_id',
        'stripe_price_monthly_id',
        'stripe_price_annual_id',
    ];

    protected $casts = [
        'price_monthly'          => 'float',
        'price_annual'           => 'float',
        'unit_price_cents'       => 'integer',
        'max_agents'             => 'integer',
        'max_calls_monthly'      => 'integer',
        'max_sms_monthly'        => 'integer',
        'has_predictive_dialer'  => 'boolean',
        'has_full_crm'           => 'boolean',
        'has_api_access'         => 'boolean',
        'has_ai_coaching'        => 'boolean',
        'has_custom_integrations'=> 'boolean',
        'has_sso'                => 'boolean',
        'has_dedicated_csm'      => 'boolean',
        'has_white_label'        => 'boolean',
        'has_on_premise'         => 'boolean',
        'has_compliance_packages'=> 'boolean',
        'is_active'              => 'boolean',
        'display_order'          => 'integer',
        'plan_order'             => 'integer',
        'trial_days'             => 'integer',
    ];

    /**
     * Get all active plans ordered by tier (cached 1 hour).
     */
    public static function getActivePlans(): \Illuminate\Database\Eloquent\Collection
    {
        return Cache::remember('active_subscription_plans', 3600, function () {
            return self::where('is_active', true)
                ->where('billing_model', 'per_seat')
                ->orderBy('plan_order')
                ->get();
        });
    }

    /**
     * Get the starter plan (used for trial assignment).
     */
    public static function getStarterPlan(): self
    {
        return Cache::remember('starter_plan', 3600, function () {
            return self::where('slug', self::SLUG_STARTER)
                ->where('is_active', true)
                ->firstOrFail();
        });
    }

    /**
     * Find plan by slug (cached 1 hour).
     */
    public static function getPlanBySlug(string $slug): ?self
    {
        return Cache::remember("plan_slug_{$slug}", 3600, function () use ($slug) {
            return self::where('slug', $slug)->first();
        });
    }

    /**
     * Check if a given feature key is enabled on this plan.
     */
    public function hasFeature(string $featureKey): bool
    {
        $column = self::FEATURE_MAP[$featureKey] ?? null;
        if (!$column) {
            return false;
        }

        return (bool) $this->{$column};
    }

    /**
     * Get all feature flags as key => bool array.
     */
    public function featureFlags(): array
    {
        $flags = [];
        foreach (self::FEATURE_MAP as $key => $column) {
            $flags[$key] = (bool) $this->{$column};
        }
        return $flags;
    }
}
