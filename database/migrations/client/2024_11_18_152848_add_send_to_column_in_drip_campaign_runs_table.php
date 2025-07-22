<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSendToColumnInDripCampaignRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('drip_campaign_runs', function (Blueprint $table) {
            $table->string('send_to');
            $table->string('schedule')->nullable();
            $table->string('schedule_day')->nullable(); 

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
