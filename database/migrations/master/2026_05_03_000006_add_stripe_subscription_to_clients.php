<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStripeSubscriptionToClients extends Migration
{
    public function up()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->string('stripe_customer_id', 60)->nullable()->after('custom_max_sms_monthly');
            $table->string('stripe_subscription_id', 60)->nullable()->after('stripe_customer_id');
            $table->string('stripe_price_id', 60)->nullable()->after('stripe_subscription_id');
        });
    }

    public function down()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_price_id',
            ]);
        });
    }
}
