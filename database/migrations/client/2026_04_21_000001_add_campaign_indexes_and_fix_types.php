<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing indexes to campaign table and fix campaign_id type mismatches
     * in child tables (BIGINT → INT UNSIGNED to match campaign.id).
     */
    public function up(): void
    {
        // 1. Add indexes to base campaign table
        Schema::table('campaign', function (Blueprint $table) {
            $table->index('group_id', 'idx_campaign_group_id');
            $table->index(['status', 'is_deleted'], 'idx_campaign_status_deleted');
        });

        // 2. Fix data type mismatches: campaign_id should be UNSIGNED INT to match campaign.id
        if (Schema::hasTable('campaign_agents')) {
            Schema::table('campaign_agents', function (Blueprint $table) {
                $table->unsignedInteger('campaign_id')->change();
            });
        }

        if (Schema::hasTable('campaign_staffing')) {
            Schema::table('campaign_staffing', function (Blueprint $table) {
                $table->unsignedInteger('campaign_id')->change();
            });
        }

        if (Schema::hasTable('campaign_numbers')) {
            Schema::table('campaign_numbers', function (Blueprint $table) {
                $table->unsignedInteger('campaign_id')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign', function (Blueprint $table) {
            $table->dropIndex('idx_campaign_group_id');
            $table->dropIndex('idx_campaign_status_deleted');
        });

        if (Schema::hasTable('campaign_agents')) {
            Schema::table('campaign_agents', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->change();
            });
        }

        if (Schema::hasTable('campaign_staffing')) {
            Schema::table('campaign_staffing', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->change();
            });
        }

        if (Schema::hasTable('campaign_numbers')) {
            Schema::table('campaign_numbers', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->change();
            });
        }
    }
};
