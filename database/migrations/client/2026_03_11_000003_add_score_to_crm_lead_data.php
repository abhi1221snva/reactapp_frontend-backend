<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a computed lead score (0–100) to crm_lead_data.
 * Updated by LeadScoringService whenever activity or fields change.
 */
class AddScoreToCrmLeadData extends Migration
{
    public function up()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            $table->tinyInteger('score')->unsigned()->default(0)->after('lead_status')->comment('Lead quality score 0-100');
            $table->index('score', 'idx_lead_score');
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
