<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds indexes on high-traffic tables to speed up common report/dialer queries.
 *
 * cdr         — filtered by campaign_id, start_time, extension, disposition_id, lead_id
 * crm_lead_data — filtered by assigned_to, lead_status, created_at, deleted_at
 * lead_temp   — filtered by list_id (campaign_id already covered by composite PK)
 */
class AddPerformanceIndexes extends Migration
{
    /**
     * Check whether a named index already exists on a table.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($result) > 0;
    }

    public function up()
    {
        // ── cdr ────────────────────────────────────────────────────────────
        Schema::table('cdr', function (Blueprint $table) {
            // Most report queries filter on campaign_id + date range together
            if (!$this->hasIndex('cdr', 'idx_cdr_campaign_start')) $table->index(['campaign_id', 'start_time'], 'idx_cdr_campaign_start');
            if (!$this->hasIndex('cdr', 'idx_cdr_extension')) $table->index('extension', 'idx_cdr_extension');
            if (!$this->hasIndex('cdr', 'idx_cdr_disposition')) $table->index('disposition_id', 'idx_cdr_disposition');
            if (!$this->hasIndex('cdr', 'idx_cdr_lead')) $table->index('lead_id', 'idx_cdr_lead');
        });

        // ── crm_lead_data ──────────────────────────────────────────────────
        Schema::table('crm_lead_data', function (Blueprint $table) {
            // Agent/manager views always filter on assigned_to
            if (!$this->hasIndex('crm_lead_data', 'idx_crm_lead_assigned_to')) $table->index('assigned_to', 'idx_crm_lead_assigned_to');
            // Status filters used across dashboard, pipeline, reports
            if (!$this->hasIndex('crm_lead_data', 'idx_crm_lead_status')) $table->index('lead_status', 'idx_crm_lead_status');
            // Date-range filters (created_at, funding_date range queries)
            if (!$this->hasIndex('crm_lead_data', 'idx_crm_lead_created_at')) $table->index('created_at', 'idx_crm_lead_created_at');
            // Soft-delete — whereNull('deleted_at') on almost every query
            if (!$this->hasIndex('crm_lead_data', 'idx_crm_lead_deleted_at')) $table->index('deleted_at', 'idx_crm_lead_deleted_at');
        });

        // ── lead_temp ──────────────────────────────────────────────────────
        Schema::table('lead_temp', function (Blueprint $table) {
            // Composite PK already covers campaign_id; add list_id for list-scoped queries
            if (!$this->hasIndex('lead_temp', 'idx_lead_temp_list')) $table->index('list_id', 'idx_lead_temp_list');
        });
    }

    public function down()
    {
        Schema::table('cdr', function (Blueprint $table) {
            $table->dropIndex('idx_cdr_campaign_start');
            $table->dropIndex('idx_cdr_extension');
            $table->dropIndex('idx_cdr_disposition');
            $table->dropIndex('idx_cdr_lead');
        });

        Schema::table('crm_lead_data', function (Blueprint $table) {
            $table->dropIndex('idx_crm_lead_assigned_to');
            $table->dropIndex('idx_crm_lead_status');
            $table->dropIndex('idx_crm_lead_created_at');
            $table->dropIndex('idx_crm_lead_deleted_at');
        });

        Schema::table('lead_temp', function (Blueprint $table) {
            $table->dropIndex('idx_lead_temp_list');
        });
    }
}
