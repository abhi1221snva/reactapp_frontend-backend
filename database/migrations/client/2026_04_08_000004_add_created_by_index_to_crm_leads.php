<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCreatedByIndexToCrmLeads extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_leads') && Schema::hasColumn('crm_leads', 'created_by')) {
            try {
                Schema::table('crm_leads', function (Blueprint $table) {
                    $table->index('created_by', 'idx_created_by');
                });
            } catch (\Exception $e) {
                // Index already exists — skip
            }
        }

        if (Schema::hasTable('crm_lead_data') && Schema::hasColumn('crm_lead_data', 'created_by')) {
            try {
                Schema::table('crm_lead_data', function (Blueprint $table) {
                    $table->index('created_by', 'idx_ld_created_by');
                });
            } catch (\Exception $e) {
                // Index already exists — skip
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_leads') && Schema::hasColumn('crm_leads', 'created_by')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                $table->dropIndex('idx_created_by');
            });
        }

        if (Schema::hasTable('crm_lead_data') && Schema::hasColumn('crm_lead_data', 'created_by')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->dropIndex('idx_ld_created_by');
            });
        }
    }
}
