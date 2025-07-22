<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCountryCodeMarketingCampaignSchedules extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('marketing_campaign_schedules', function (Blueprint $table) {
            $table->unsignedInteger('sms_country_code')->nullable()->after("sms_template_id");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('marketing_campaign_schedules', function (Blueprint $table) {
            $table->dropColumn("sms_country_code");
        });
    }
}
