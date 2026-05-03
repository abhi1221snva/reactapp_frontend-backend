<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert from single per-seat plan to 4 tiered per-seat plans.
     *
     * 1. Adds plan_order to subscription_plans for tier ranking
     * 2. Reactivates + updates legacy starter/growth/pro/enterprise plans with per-seat pricing
     * 3. Deactivates the old per_seat plan
     * 4. Backfills clients from per_seat → starter plan
     * 5. Adds min_plan_order to sidebar_menu_items for plan-based menu gating
     */
    public function up(): void
    {
        // ── 1. Add plan_order column ──────────────────────────────────────
        if (!Schema::connection('master')->hasColumn('subscription_plans', 'plan_order')) {
            Schema::connection('master')->table('subscription_plans', function (Blueprint $table) {
                $table->unsignedTinyInteger('plan_order')->default(0)->after('display_order');
            });
        }

        // ── 2. Reactivate + update the 4 tiered plans ────────────────────
        // Starter: $29/seat/mo — Basic CRM only
        DB::connection('master')->table('subscription_plans')
            ->where('slug', 'starter')
            ->update([
                'name'                     => 'Starter',
                'description'              => 'Basic CRM & lead management — $29 per seat per month',
                'price_monthly'            => 29.00,
                'unit_price_cents'         => 2900,
                'billing_model'            => 'per_seat',
                'is_active'                => 1,
                'display_order'            => 1,
                'plan_order'               => 1,
                'trial_days'               => 14,
                'max_agents'               => 0,
                'max_calls_monthly'        => 0,
                'max_sms_monthly'          => 0,
                'has_predictive_dialer'    => false,
                'has_full_crm'             => true,
                'has_api_access'           => false,
                'has_ai_coaching'          => false,
                'has_custom_integrations'  => false,
                'has_sso'                  => false,
                'has_dedicated_csm'        => false,
                'has_white_label'          => false,
                'has_on_premise'           => false,
                'has_compliance_packages'  => false,
                'updated_at'              => now(),
            ]);

        // Growth: $39/seat/mo — + Dialer, API, advanced reports, SMS
        DB::connection('master')->table('subscription_plans')
            ->where('slug', 'growth')
            ->update([
                'name'                     => 'Growth',
                'description'              => 'Dialer & advanced features — $39 per seat per month',
                'price_monthly'            => 39.00,
                'unit_price_cents'         => 3900,
                'billing_model'            => 'per_seat',
                'is_active'                => 1,
                'display_order'            => 2,
                'plan_order'               => 2,
                'trial_days'               => 14,
                'max_agents'               => 0,
                'max_calls_monthly'        => 0,
                'max_sms_monthly'          => 0,
                'has_predictive_dialer'    => true,
                'has_full_crm'             => true,
                'has_api_access'           => true,
                'has_ai_coaching'          => false,
                'has_custom_integrations'  => false,
                'has_sso'                  => false,
                'has_dedicated_csm'        => false,
                'has_white_label'          => false,
                'has_on_premise'           => false,
                'has_compliance_packages'  => false,
                'updated_at'              => now(),
            ]);

        // Pro: $49/seat/mo — + AI, integrations, IVR, workforce
        DB::connection('master')->table('subscription_plans')
            ->where('slug', 'pro')
            ->update([
                'name'                     => 'Pro',
                'description'              => 'AI coaching & integrations — $49 per seat per month',
                'price_monthly'            => 49.00,
                'unit_price_cents'         => 4900,
                'billing_model'            => 'per_seat',
                'is_active'                => 1,
                'display_order'            => 3,
                'plan_order'               => 3,
                'trial_days'               => 14,
                'max_agents'               => 0,
                'max_calls_monthly'        => 0,
                'max_sms_monthly'          => 0,
                'has_predictive_dialer'    => true,
                'has_full_crm'             => true,
                'has_api_access'           => true,
                'has_ai_coaching'          => true,
                'has_custom_integrations'  => true,
                'has_sso'                  => false,
                'has_dedicated_csm'        => false,
                'has_white_label'          => false,
                'has_on_premise'           => false,
                'has_compliance_packages'  => false,
                'updated_at'              => now(),
            ]);

        // Enterprise: $59/seat/mo — All features
        DB::connection('master')->table('subscription_plans')
            ->where('slug', 'enterprise')
            ->update([
                'name'                     => 'Enterprise',
                'description'              => 'Full platform with SSO, compliance & white label — $59 per seat per month',
                'price_monthly'            => 59.00,
                'unit_price_cents'         => 5900,
                'billing_model'            => 'per_seat',
                'is_active'                => 1,
                'display_order'            => 4,
                'plan_order'               => 4,
                'trial_days'               => 14,
                'max_agents'               => 0,
                'max_calls_monthly'        => 0,
                'max_sms_monthly'          => 0,
                'has_predictive_dialer'    => true,
                'has_full_crm'             => true,
                'has_api_access'           => true,
                'has_ai_coaching'          => true,
                'has_custom_integrations'  => true,
                'has_sso'                  => true,
                'has_dedicated_csm'        => true,
                'has_white_label'          => true,
                'has_on_premise'           => true,
                'has_compliance_packages'  => true,
                'updated_at'              => now(),
            ]);

        // ── 3. Deactivate old per_seat plan ───────────────────────────────
        DB::connection('master')->table('subscription_plans')
            ->where('slug', 'per_seat')
            ->update([
                'is_active'    => 0,
                'billing_model' => 'legacy',
                'updated_at'   => now(),
            ]);

        // ── 4. Backfill clients from per_seat → starter plan ──────────────
        $perSeatPlanId = DB::connection('master')->table('subscription_plans')
            ->where('slug', 'per_seat')
            ->value('id');

        $starterPlanId = DB::connection('master')->table('subscription_plans')
            ->where('slug', 'starter')
            ->value('id');

        if ($perSeatPlanId && $starterPlanId) {
            DB::connection('master')->table('clients')
                ->where('subscription_plan_id', $perSeatPlanId)
                ->update([
                    'subscription_plan_id' => $starterPlanId,
                    'updated_at'           => now(),
                ]);
        }

        // ── 5. Add min_plan_order to sidebar_menu_items ───────────────────
        if (!Schema::connection('master')->hasColumn('sidebar_menu_items', 'min_plan_order')) {
            Schema::connection('master')->table('sidebar_menu_items', function (Blueprint $table) {
                $table->unsignedTinyInteger('min_plan_order')->default(0)->after('min_level');
            });
        }

        // ── 6. Set min_plan_order on menu items ───────────────────────────
        // Growth+ (plan_order >= 2): Campaigns, Dialer, advanced reports, SMS, agents
        $growthRoutes = [
            '/campaigns',
            '/dialer-studio',
            '/monitoring',
            '/sms',
            '/reports/daily',
            '/reports/agent-summary',
            '/reports/disposition',
            '/reports/campaign-performance',
            '/reports/live',
            '/agents',
            '/ring-groups',
            '/extension-groups',
            '/voicemail',
            '/call-timers',
            '/holidays',
            '/settings/dispositions',
            '/settings/recycle-rules',
            '/settings/lead-activity',
            '/settings/email-templates',
            '/settings/sms-templates',
        ];
        DB::connection('master')->table('sidebar_menu_items')
            ->whereIn('route_path', $growthRoutes)
            ->update(['min_plan_order' => 2]);

        // Pro+ (plan_order >= 3): AI, SMS AI, IVR, Ringless, Workforce
        $proRoutes = [
            '/ai/settings',
            '/ai/coach',
            '/ringless',
            '/smsai/demo',
            '/smsai/campaigns',
            '/smsai/lists',
            '/smsai/reports',
            '/smsai/templates',
            '/ivr',
            '/workforce',
            '/workforce/shifts',
            '/workforce/staffing',
            '/workforce/reports',
            '/workforce/analytics',
        ];
        DB::connection('master')->table('sidebar_menu_items')
            ->whereIn('route_path', $proRoutes)
            ->update(['min_plan_order' => 3]);

        // Enterprise (plan_order >= 4): Telecom Hub
        $enterpriseRoutes = [
            '/telecom',
            '/telecom?p=twilio&t=numbers',
            '/telecom?p=twilio&t=trunks',
            '/telecom?p=twilio&t=calls',
            '/telecom?p=twilio&t=sms',
            '/telecom?p=twilio&t=usage',
        ];
        DB::connection('master')->table('sidebar_menu_items')
            ->whereIn('route_path', $enterpriseRoutes)
            ->update(['min_plan_order' => 4]);
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // Reactivate per_seat plan
        DB::connection('master')->table('subscription_plans')
            ->where('slug', 'per_seat')
            ->update(['is_active' => 1, 'billing_model' => 'per_seat']);

        // Deactivate tiered plans
        DB::connection('master')->table('subscription_plans')
            ->whereIn('slug', ['starter', 'growth', 'pro', 'enterprise'])
            ->update(['is_active' => 0, 'billing_model' => 'legacy']);

        // Move clients back to per_seat plan
        $perSeatPlanId = DB::connection('master')->table('subscription_plans')
            ->where('slug', 'per_seat')
            ->value('id');
        $starterPlanId = DB::connection('master')->table('subscription_plans')
            ->where('slug', 'starter')
            ->value('id');

        if ($perSeatPlanId && $starterPlanId) {
            DB::connection('master')->table('clients')
                ->where('subscription_plan_id', $starterPlanId)
                ->update(['subscription_plan_id' => $perSeatPlanId]);
        }

        // Reset min_plan_order
        if (Schema::connection('master')->hasColumn('sidebar_menu_items', 'min_plan_order')) {
            DB::connection('master')->table('sidebar_menu_items')
                ->update(['min_plan_order' => 0]);

            Schema::connection('master')->table('sidebar_menu_items', function (Blueprint $table) {
                $table->dropColumn('min_plan_order');
            });
        }

        // Drop plan_order column
        if (Schema::connection('master')->hasColumn('subscription_plans', 'plan_order')) {
            Schema::connection('master')->table('subscription_plans', function (Blueprint $table) {
                $table->dropColumn('plan_order');
            });
        }
    }
};
