<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStripeIdsToSubscriptionPlans extends Migration
{
    public function up()
    {
        Schema::connection('master')->table('subscription_plans', function (Blueprint $table) {
            $table->string('stripe_product_id', 60)->nullable()->after('trial_days');
            $table->string('stripe_price_monthly_id', 60)->nullable()->after('stripe_product_id');
            $table->string('stripe_price_annual_id', 60)->nullable()->after('stripe_price_monthly_id');
        });
    }

    public function down()
    {
        Schema::connection('master')->table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_product_id',
                'stripe_price_monthly_id',
                'stripe_price_annual_id',
            ]);
        });
    }
}
