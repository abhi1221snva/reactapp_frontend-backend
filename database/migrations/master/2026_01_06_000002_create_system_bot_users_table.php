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
        Schema::connection('master')->create('system_bot_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('parent_id')->comment('Client ID');
            $table->string('bot_type', 50)->comment('e.g., gmail_bot, system_bot');
            $table->unsignedInteger('user_id')->comment('Actual user ID for the bot');
            $table->string('display_name', 100);
            $table->string('avatar', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['parent_id', 'bot_type']);
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('master')->dropIfExists('system_bot_users');
    }
};
