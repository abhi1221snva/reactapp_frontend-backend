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
        Schema::create('gmail_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->enum('notification_type', ['dm', 'channel'])->default('dm');
            $table->string('channel_uuid', 36)->nullable()->comment('Team conversation UUID if channel type');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('include_subject')->default(true);
            $table->boolean('include_sender')->default(true);
            $table->boolean('include_preview')->default(true);
            $table->unsignedSmallInteger('preview_length')->default(200);
            $table->boolean('include_attachments_list')->default(true);
            $table->boolean('include_email_link')->default(true);
            $table->json('filter_labels')->nullable()->comment('Gmail labels to filter, e.g., ["INBOX","IMPORTANT"]');
            $table->json('exclude_labels')->nullable()->comment('Gmail labels to exclude');
            $table->boolean('only_unread')->default(true);
            $table->timestamps();

            $table->unique(['user_id']);
            $table->index('is_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_notification_settings');
    }
};
