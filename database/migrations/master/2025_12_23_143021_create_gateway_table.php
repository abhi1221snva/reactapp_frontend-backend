<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gateway_credentials', function (Blueprint $table) {
            $table->id();
              // Public UUID for API usage
            $table->uuid('uuid')->unique();

            // User reference (from X-Easify-User-Token)
            $table->uuid('user_uuid')->index();

            // Provider info
            $table->string('provider');   // twilio, vonage, messagebird
            $table->string('type');       // sms

            // Encrypted credentials
            $table->json('credentials');

            $table->timestamps();
            $table->softDeletes();

            // Prevent duplicate provider per user
            $table->unique(['user_uuid', 'provider', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gateway');
    }
};
