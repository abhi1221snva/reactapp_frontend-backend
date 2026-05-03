<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert from multi-tier plans to single per-seat ($29/user/month) billing.
     *
     * 1. Adds unit_price_cents + billing_model to subscription_plans
     * 2. Adds seat_quantity to clients
     * 3. Deactivates legacy plans (starter, growth, pro, enterprise)
     * 4. Inserts the single per-seat plan with all features enabled
     * 5. Backfills existing active/trial clients with their current user count
     */
    public function up(): void
    {
        // ── 1. Extend subscription_plans ────────────────────────────────────
        Schema::connection('master')->table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_cents')->default(2900)->after('price_annual');
            $table->string('billing_model', 20)->default('per_seat')->after('trial_days');
        });

        // ── 2. Add seat_quantity to clients ─────────────────────────────────
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->unsignedInteger('seat_quantity')->default(1)->after('stripe_price_id');
        });

        // ── 3. Deactivate legacy plans ──────────────────────────────────────
        DB::connection('master')->table('subscription_plans')
            ->whereIn('slug', ['starter', 'growth', 'pro', 'enterprise'])
            ->update([
                'is_active'     => 0,
                'billing_model' => 'legacy',
                'updated_at'    => now(),
            ]);

        // ── 4. Insert the per-seat plan ─────────────────────────────────────
        DB::connection('master')->table('subscription_plans')->insert([
            'slug'                     => 'per_seat',
            'name'                     => 'Rocket Dialer',
            'description'              => 'Full platform access — $29 per seat per month',
            'price_monthly'            => 29.00,
            'price_annual'             => 0,
            'unit_price_cents'         => 2900,
            'max_agents'               => 0,  // unlimited — seat limit is on client
            'max_calls_monthly'        => 0,  // unlimited
            'max_sms_monthly'          => 0,  // unlimited
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
            'is_active'                => true,
            'display_order'            => 1,
            'trial_days'               => 14,
            'billing_model'            => 'per_seat',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        // ── 5. Backfill existing clients ────────────────────────────────────
        $perSeatPlanId = DB::connection('master')->table('subscription_plans')
            ->where('slug', 'per_seat')
            ->value('id');

        $activeClients = DB::connection('master')->table('clients')
            ->whereIn('subscription_status', ['active', 'trial'])
            ->where('is_deleted', 0)
            ->get(['id', 'subscription_plan_id']);

        foreach ($activeClients as $client) {
            $activeUserCount = DB::connection('master')->table('users')
                ->where('parent_id', $client->id)
                ->where('is_deleted', 0)
                ->where('status', 1)
                ->count();

            DB::connection('master')->table('clients')
                ->where('id', $client->id)
                ->update([
                    'seat_quantity'        => max(1, $activeUserCount),
                    'subscription_plan_id' => $perSeatPlanId,
                    'billing_cycle'        => 'monthly',
                    'updated_at'           => now(),
                ]);
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // Re-activate legacy plans
        DB::connection('master')->table('subscription_plans')
            ->whereIn('slug', ['starter', 'growth', 'pro', 'enterprise'])
            ->update(['is_active' => 1, 'billing_model' => 'legacy']);

        // Remove the per-seat plan
        DB::connection('master')->table('subscription_plans')
            ->where('slug', 'per_seat')
            ->delete();

        // Drop new columns
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->dropColumn('seat_quantity');
        });

        Schema::connection('master')->table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['unit_price_cents', 'billing_model']);
        });
    }
};
