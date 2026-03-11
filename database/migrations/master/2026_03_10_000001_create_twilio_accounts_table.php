<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioAccountsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('twilio_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id')->unique();
            // Null = use platform master account (auto-subaccount)
            $table->text('account_sid')->nullable();   // AES-encrypted
            $table->text('auth_token')->nullable();    // AES-encrypted
            $table->string('friendly_name')->nullable();
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            // When using platform subaccounts
            $table->string('subaccount_sid', 64)->nullable();
            $table->text('subaccount_token')->nullable(); // AES-encrypted
            // Fraud / country blocklist stored as JSON array of ISO-2 codes
            $table->json('blocked_countries')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('twilio_accounts');
    }
}
