<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds a computed lead score (0–100) to crm_lead_data.
 * Updated by LeadScoringService whenever activity or fields change.
 */
class AddScoreToCrmLeadData extends Migration
{
    private function hasIndex(string $table, string $indexName): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($result) > 0;
    }

    public function up()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_lead_data', 'score')) $table->tinyInteger('score')->unsigned()->default(0)->after('lead_status')->comment('Lead quality score 0-100');
            if (!$this->hasIndex('crm_lead_data', 'idx_lead_score')) $table->index('score', 'idx_lead_score');
        });
    }

    public function down()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            $table->dropIndex('idx_lead_score');
            $table->dropColumn('score');
        });
    }
}
