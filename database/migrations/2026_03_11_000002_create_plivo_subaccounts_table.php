<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlivoSubaccountsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('plivo_subaccounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plivo_account_id');
            $table->string('auth_id', 64)->unique();
            $table->text('auth_token')->nullable(); // AES-encrypted
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->enum('status', ['active', 'suspended', 'deleted'])->default('active');
            $table->timestamps();

            $table->foreign('plivo_account_id')
                  ->references('id')->on('plivo_accounts')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('plivo_subaccounts');
    }
}
