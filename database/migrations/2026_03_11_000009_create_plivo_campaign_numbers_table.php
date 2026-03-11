<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoCampaignNumbersTable extends Migration
{
    public function up(): void
    {
        Schema::create('plivo_campaign_numbers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('plivo_number_id');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'plivo_number_id']);
            $table->index('campaign_id');
            $table->index('plivo_number_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plivo_campaign_numbers');
    }
}
