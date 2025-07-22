<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeColumnNameInDripCampaignSchedulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('drip_campaign_schedules', function (Blueprint $table) {
            DB::statement('ALTER TABLE drip_campaign_schedules CHANGE send_count sent_count INT(10) DEFAULT 0;');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('drip_campaign_schedules', function (Blueprint $table) {
            //
        });
    }
}
