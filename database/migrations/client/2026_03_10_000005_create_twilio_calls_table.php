<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioCallsTable extends Migration
{
    public function up(): void
    {
        Schema::create('twilio_calls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('call_sid', 64)->unique();     // CA...
            $table->string('from_number', 20);
            $table->string('to_number', 20);
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->enum('status', [
                'queued','ringing','in-progress','completed',
                'busy','no-answer','canceled','failed'
            ])->default('queued');
            $table->unsignedInteger('duration')->default(0); // seconds
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('recording_sid', 64)->nullable(); // RE...
            $table->string('recording_url')->nullable();
            $table->decimal('price', 10, 6)->nullable();
            $table->string('price_unit', 5)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('agent_id');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_calls');
    }
}
