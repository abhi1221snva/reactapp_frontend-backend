<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterRunTimeColumnInDripCampaignSchedulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('drip_campaign_schedules', function (Blueprint $table) {
            DB::statement("ALTER TABLE drip_campaign_schedules MODIFY COLUMN run_time time");

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
