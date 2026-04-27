<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDispositionIdToCampaignLeadQueue extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_queue', function (Blueprint $table) {
            $table->unsignedInteger('disposition_id')->nullable()->after('status');
            $table->index(['campaign_id', 'status', 'disposition_id'], 'clq_campaign_status_dispo');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_queue', function (Blueprint $table) {
            $table->dropIndex('clq_campaign_status_dispo');
            $table->dropColumn('disposition_id');
        });
    }
}
