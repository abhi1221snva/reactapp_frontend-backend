<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoSmsTable extends Migration
{
    public function up(): void
    {
        Schema::create('plivo_sms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('message_uuid', 64)->unique();   // Plivo message UUID
            $table->string('from_number', 20);
            $table->string('to_number', 20);
            $table->text('message_body');
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->string('message_state')->default('queued');
            // queued, sent, delivered, undelivered, rejected, received
            $table->string('message_type')->nullable();     // sms, mms
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->decimal('total_amount', 10, 6)->nullable();
            $table->string('total_rate', 20)->nullable();
            $table->string('units')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('direction');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plivo_sms');
    }
}
