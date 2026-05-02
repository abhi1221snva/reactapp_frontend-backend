<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubscriptionEventsTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('subscription_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('client_id')->index();
            $table->string('event_type', 40);
            // trial_started, trial_expired, subscribed, upgraded,
            // payment_failed, payment_recovered, cancelled, expired,
            // resumed, wallet_topup, wallet_debit, grace_started, grace_ended
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->unsignedInteger('plan_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('triggered_by', 60)->default('system');
            // 'system', 'stripe_webhook', 'user:{id}', 'admin:{id}', 'scheduler'
            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_id', 'created_at'], 'idx_sub_events_client_time');
            $table->index('event_type', 'idx_sub_events_type');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('subscription_events');
    }
}
