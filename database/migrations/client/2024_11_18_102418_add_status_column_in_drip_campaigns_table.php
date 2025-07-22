<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusColumnInDripCampaignsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('drip_campaigns', function (Blueprint $table) {
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-inactive,1-active');
        });
    }
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('drip_campaigns', function (Blueprint $table) {
            //
        });
    }
}
