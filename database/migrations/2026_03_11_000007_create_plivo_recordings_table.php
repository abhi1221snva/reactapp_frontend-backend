<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoRecordingsTable extends Migration
{
    public function up(): void
    {
        Schema::create('plivo_recordings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('recording_id', 64)->unique();   // Plivo recording ID (UUID)
            $table->string('call_uuid', 64)->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->string('recording_url')->nullable();
            $table->enum('recording_type', ['conference', 'call'])->default('call');
            $table->enum('status', ['in-progress', 'completed', 'failed', 'deleted'])->default('completed');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->timestamp('add_time')->nullable();      // Plivo's add_time field
            $table->timestamps();

            $table->index('call_uuid');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plivo_recordings');
    }
}
