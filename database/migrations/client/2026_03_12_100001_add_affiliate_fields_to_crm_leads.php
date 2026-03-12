<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAffiliateFieldsToCrmLeads extends Migration
{
    public function up()
    {
        // Only add to crm_leads — crm_lead_data is at row-size limit
        if (Schema::hasTable('crm_leads')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                if (!Schema::hasColumn('crm_leads', 'lead_token')) {
                    $table->string('lead_token', 100)->nullable()->unique()->after('unique_url');
                }
                if (!Schema::hasColumn('crm_leads', 'affiliate_user_id')) {
                    $table->unsignedBigInteger('affiliate_user_id')->nullable()->after('lead_token');
                }
                if (!Schema::hasColumn('crm_leads', 'affiliate_code')) {
                    $table->string('affiliate_code', 80)->nullable()->after('affiliate_user_id');
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('crm_leads')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                foreach (['lead_token', 'affiliate_user_id', 'affiliate_code'] as $col) {
                    if (Schema::hasColumn('crm_leads', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
}
