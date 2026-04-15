<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add dialer_status column to the campaign table.
 *
 * The existing boolean `status` column (0/1 active/inactive) must NOT be
 * changed because many other parts of the codebase depend on it.
 *
 * dialer_status tracks the auto-dialer lifecycle separately:
 *   NULL     — never started / not an auto-dial campaign
 *   running  — currently dialing
 *   paused   — manually paused by supervisor
 *   completed — all leads exhausted
 */
class AddDialerStatusToCampaignTable extends Migration
{
    public function up(): void
    {
        Schema::table('campaign', function (Blueprint $table) {
            $table->enum('dialer_status', ['running', 'paused', 'completed'])
                  ->nullable()
                  ->default(null)
                  ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('campaign', function (Blueprint $table) {
            $table->dropColumn('dialer_status');
        });
    }
}
