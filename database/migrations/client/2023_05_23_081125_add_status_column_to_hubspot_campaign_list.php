<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusColumnToHubspotCampaignList extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('hubspot_campaign_list', function (Blueprint $table) {
            $table->enum('status', array('0','1'))->default('1'); // 0-inactive,1-active
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('hubspot_campaign_list', function (Blueprint $table) {
            //
        });
    }
}
