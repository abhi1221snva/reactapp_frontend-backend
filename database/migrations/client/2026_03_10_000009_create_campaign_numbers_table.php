<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCampaignNumbersTable extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_numbers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('twilio_number_id');
            $table->boolean('is_active')->default(1);
            $table->timestamp('last_used_at')->nullable(); // for round-robin rotation
            $table->timestamps();

            $table->unique(['campaign_id', 'twilio_number_id']);
            $table->index(['campaign_id', 'is_active', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_numbers');
    }
}
