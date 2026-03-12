<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioNumbersTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('twilio_numbers')) return;
        Schema::create('twilio_numbers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sid', 64)->unique();          // Twilio PhoneNumber SID (PN...)
            $table->string('phone_number', 20);
            $table->string('friendly_name', 50)->nullable();
            $table->string('country_code', 5)->default('US');
            $table->json('capabilities')->nullable();     // {"voice":true,"sms":true,"mms":true}
            $table->enum('status', ['active', 'released'])->default('active');
            $table->string('voice_url')->nullable();
            $table->string('sms_url')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_numbers');
    }
}
