<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add system channel support to team_conversations.
 *
 * is_system   — marks channels that cannot be left, renamed, or deleted
 * system_slug — unique key per system channel (e.g. 'lender', 'merchant')
 *
 * Run per client DB:
 *   php artisan migrate --path=database/migrations/client/2026_03_31_000002_add_is_system_to_team_conversations.php
 */
class AddIsSystemToTeamConversations extends Migration
{
    public function up(): void
    {
        Schema::table('team_conversations', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->string('system_slug', 50)->nullable()->unique()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('team_conversations', function (Blueprint $table) {
            $table->dropColumn(['system_slug', 'is_system']);
        });
    }
}
