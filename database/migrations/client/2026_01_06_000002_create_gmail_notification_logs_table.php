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
        Schema::create('gmail_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('gmail_message_id', 100)->comment('Gmail unique message ID');
            $table->string('thread_id', 100)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('sender_email', 255);
            $table->string('sender_name', 255)->nullable();
            $table->unsignedBigInteger('team_message_id')->nullable()->comment('ID of sent team chat message');
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('gmail_date')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'gmail_message_id']);
            $table->index(['user_id', 'status']);
            $table->index('gmail_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_notification_logs');
    }
};
