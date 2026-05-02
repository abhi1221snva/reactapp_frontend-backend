<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubscriptionToClients extends Migration
{
    public function up()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->unsignedInteger('subscription_plan_id')->nullable()->after('mca_crm');
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly')->after('subscription_plan_id');
            $table->timestamp('subscription_started_at')->nullable()->after('billing_cycle');
            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_started_at');
            $table->enum('subscription_status', ['active', 'trial', 'past_due', 'cancelled', 'expired'])
                  ->default('trial')
                  ->after('subscription_ends_at');
            // Enterprise-only custom overrides (nullable = use plan defaults)
            $table->unsignedInteger('custom_max_agents')->nullable()->after('subscription_status');
            $table->unsignedInteger('custom_max_calls_monthly')->nullable()->after('custom_max_agents');
            $table->unsignedInteger('custom_max_sms_monthly')->nullable()->after('custom_max_calls_monthly');

            $table->index('subscription_plan_id', 'idx_clients_sub_plan');
            $table->index('subscription_status', 'idx_clients_sub_status');
        });
    }

    public function down()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->dropIndex('idx_clients_sub_plan');
            $table->dropIndex('idx_clients_sub_status');
            $table->dropColumn([
                'subscription_plan_id',
                'billing_cycle',
                'subscription_started_at',
                'subscription_ends_at',
                'subscription_status',
                'custom_max_agents',
                'custom_max_calls_monthly',
                'custom_max_sms_monthly',
            ]);
        });
    }
}
