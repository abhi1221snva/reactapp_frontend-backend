<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioRecordingsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('twilio_recordings')) return;
        Schema::create('twilio_recordings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('recording_sid', 64)->unique(); // RE...
            $table->string('call_sid', 64)->nullable();    // CA...
            $table->unsignedInteger('duration')->default(0);
            $table->string('url')->nullable();
            $table->enum('status', ['in-progress', 'completed', 'failed', 'deleted'])->default('completed');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index('call_sid');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_recordings');
    }
}
