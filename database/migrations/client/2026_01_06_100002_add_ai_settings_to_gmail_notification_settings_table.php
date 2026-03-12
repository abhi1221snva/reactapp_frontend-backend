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
        Schema::table('gmail_notification_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('gmail_notification_settings', 'ai_analysis_enabled')) $table->boolean('ai_analysis_enabled')->default(true)->after('only_unread');
            if (!Schema::hasColumn('gmail_notification_settings', 'include_ai_summary')) $table->boolean('include_ai_summary')->default(true)->after('ai_analysis_enabled');
            if (!Schema::hasColumn('gmail_notification_settings', 'include_priority')) $table->boolean('include_priority')->default(true)->after('include_ai_summary');
            if (!Schema::hasColumn('gmail_notification_settings', 'include_suggested_reply')) $table->boolean('include_suggested_reply')->default(true)->after('include_priority');
            if (!Schema::hasColumn('gmail_notification_settings', 'min_priority_notify')) $table->enum('min_priority_notify', ['high', 'medium', 'low'])->default('low')->after('include_suggested_reply');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmail_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ai_analysis_enabled',
                'include_ai_summary',
                'include_priority',
                'include_suggested_reply',
                'min_priority_notify',
            ]);
        });
    }
};
