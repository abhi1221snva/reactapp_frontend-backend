<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeColumnTypeInDripCampaignRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('drip_campaign_runs', function (Blueprint $table) {
            DB::statement("ALTER TABLE drip_campaign_runs MODIFY COLUMN scheduled_time time");

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('drip_campaign_runs', function (Blueprint $table) {
            //
        });
    }
}
