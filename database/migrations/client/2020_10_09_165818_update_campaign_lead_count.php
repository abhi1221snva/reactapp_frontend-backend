<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateCampaignLeadCount extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::raw("ALTER TABLE `campaign` CHANGE COLUMN `max_lead_temp` `max_lead_temp` INT NOT NULL DEFAULT 500, CHANGE COLUMN `min_lead_temp` `min_lead_temp` INT NOT NULL DEFAULT 100;");
        DB::raw("SET SQL_SAFE_UPDATES=0;");
        DB::raw("UPDATE campaign SET max_lead_temp=500, min_lead_temp=100 WHERE max_lead_temp=100 AND min_lead_temp=500");
        DB::raw("SET SQL_SAFE_UPDATES=1;");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
