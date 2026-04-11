<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_events — append-only audit log.
 *
 * Every state transition, provider callback, and webhook delivery attempt
 * writes one row. Powers the timeline view, compliance audit, and
 * reconciliation reports. Intentionally denormalized for write speed.
 */
class CreateRvmEventsTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('drop_id', 26);
            $table->unsignedInteger('client_id');

            // queued|dispatched|delivered|failed|deferred|cancelled|
            // webhook_sent|webhook_failed|provider_callback|...
            $table->string('type', 48);

            $table->string('provider', 32)->nullable();
            $table->json('payload')->nullable();

            // Millisecond precision — matters for reconciliation ordering
            $table->dateTime('occurred_at', 3);

            $table->index('drop_id', 'idx_rvm_events_drop');
            $table->index(['client_id', 'occurred_at'], 'idx_rvm_events_client_time');
            $table->index(['type', 'occurred_at'], 'idx_rvm_events_type_time');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_events');
    }
}
