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
        Schema::create('prompt_functions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prompt_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['sms', 'call']);
            $table->string('name', 255);
            $table->text('message')->nullable();
            $table->string('phone', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompt_functions');
    }
};
