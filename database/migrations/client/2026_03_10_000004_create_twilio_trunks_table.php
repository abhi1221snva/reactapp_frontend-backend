<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioTrunksTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('twilio_trunks')) return;
        Schema::create('twilio_trunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sid', 64)->unique();          // Twilio Trunk SID (TK...)
            $table->string('friendly_name');
            $table->string('domain_name')->nullable();   // e.g. mytrunk.pstn.twilio.com
            $table->string('origination_url')->nullable();
            $table->enum('status', ['active', 'deleted'])->default('active');
            $table->json('ip_acl')->nullable();           // Allowed IP ranges
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_trunks');
    }
}
