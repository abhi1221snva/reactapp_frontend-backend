<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWalletConfigToClients extends Migration
{
    public function up()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->integer('wallet_balance_cents')->default(0)->after('stripe_price_id');
            $table->integer('wallet_low_threshold_cents')->default(200)->after('wallet_balance_cents'); // $2.00
            $table->boolean('wallet_low_notified')->default(false)->after('wallet_low_threshold_cents');
            $table->timestamp('grace_period_ends_at')->nullable()->after('subscription_ends_at');
        });
    }

    public function down()
    {
        Schema::connection('master')->table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'wallet_balance_cents',
                'wallet_low_threshold_cents',
                'wallet_low_notified',
                'grace_period_ends_at',
            ]);
        });
    }
}
