<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_webhook_endpoints — tenant-registered callback URLs.
 * rvm_webhook_deliveries — per-event delivery attempts with retry ledger.
 *
 * Outbound webhooks go through a dedicated Redis queue (rvm.webhooks) so
 * a slow tenant endpoint can't back-pressure drop dispatch.
 */
class CreateRvmWebhookTables extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_webhook_endpoints', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('client_id');
            $table->string('url', 512);
            $table->string('secret', 128);                   // HMAC-SHA256 signing secret
            $table->json('events')->nullable();              // ["rvm.drop.*","rvm.campaign.completed"]
            $table->boolean('active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->dateTime('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['client_id', 'active'], 'idx_rvm_webhook_endpoints_client_active');
        });

        Schema::connection('master')->create('rvm_webhook_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('endpoint_id');
            $table->unsignedInteger('client_id');
            $table->char('drop_id', 26)->nullable();
            $table->string('event_id', 32);                  // ULID-ish per event
            $table->string('event_type', 64);

            $table->enum('status', ['pending', 'delivered', 'failed', 'giving_up'])->default('pending');
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('delivered_at')->nullable();

            $table->json('payload');                         // full body we sent
            $table->timestamps();

            $table->index(['endpoint_id', 'status'], 'idx_rvm_webhook_deliveries_ep_status');
            $table->index(['status', 'next_retry_at'], 'idx_rvm_webhook_deliveries_retry');
            $table->index('drop_id', 'idx_rvm_webhook_deliveries_drop');
            $table->index('event_id', 'idx_rvm_webhook_deliveries_event');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_webhook_deliveries');
        Schema::connection('master')->dropIfExists('rvm_webhook_endpoints');
    }
}
