<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoCallsTable extends Migration
{
    public function up(): void
    {
        Schema::create('plivo_calls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('call_uuid', 64)->unique();      // Plivo call UUID (GUID)
            $table->string('from_number', 20);
            $table->string('to_number', 20);
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->enum('call_status', [
                'queued', 'ringing', 'in-progress', 'completed',
                'busy', 'no-answer', 'canceled', 'failed'
            ])->default('queued');
            $table->unsignedInteger('duration')->default(0);    // seconds
            $table->unsignedInteger('bill_duration')->default(0); // billable seconds
            $table->decimal('total_amount', 10, 6)->nullable();
            $table->string('total_rate', 20)->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('recording_url')->nullable();
            $table->string('record_url')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('agent_id');
            $table->index('call_status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plivo_calls');
    }
}
