<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoAccountsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('plivo_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id')->unique();
            $table->text('auth_id')->nullable();           // AES-encrypted Plivo Auth ID
            $table->text('auth_token')->nullable();        // AES-encrypted Plivo Auth Token
            $table->string('name')->nullable();            // Plivo account name
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            // Subaccount fields (when using platform subaccounts)
            $table->string('subaccount_auth_id', 64)->nullable();
            $table->text('subaccount_auth_token')->nullable(); // AES-encrypted
            // Fraud / country blocklist stored as JSON array of ISO-2 codes
            $table->json('blocked_countries')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('plivo_accounts');
    }
}
