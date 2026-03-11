<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoNumbersTable extends Migration
{
    public function up(): void
    {
        Schema::create('plivo_numbers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('number', 20)->unique();         // E.164 phone number
            $table->string('number_uuid', 64)->nullable();  // Plivo internal UUID
            $table->string('alias')->nullable();
            $table->string('country_iso', 5)->default('US');
            $table->json('sub_type')->nullable();           // {"key":"fixed","voice":"true","sms":"true"}
            $table->enum('status', ['active', 'released'])->default('active');
            $table->string('voice_url')->nullable();
            $table->string('sms_url')->nullable();
            $table->string('app_id')->nullable();           // Plivo Application ID bound to number
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plivo_numbers');
    }
}
