<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioSubaccountsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('twilio_subaccounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('twilio_account_id');
            $table->string('sid', 64)->unique();
            $table->text('auth_token')->nullable(); // AES-encrypted
            $table->string('friendly_name');
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->timestamps();

            $table->foreign('twilio_account_id')
                  ->references('id')->on('twilio_accounts')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('twilio_subaccounts');
    }
}
