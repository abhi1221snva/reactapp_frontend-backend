<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $connection = 'master';
    protected $table = 'subscription_plans';

    const SLUG_STARTER    = 'starter';
    const SLUG_GROWTH     = 'growth';
    const SLUG_PRO        = 'pro';
    const SLUG_ENTERPRISE = 'enterprise';

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
        'trial_days',
        'stripe_product_id',
        'stripe_price_monthly_id',
        'stripe_price_annual_id',
    ];

    protected $casts = [
        'price_monthly'          => 'float',
        'price_annual'           => 'float',
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
        'trial_days'             => 'integer',
    ];

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
