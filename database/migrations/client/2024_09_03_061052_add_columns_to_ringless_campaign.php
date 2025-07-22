<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToRinglessCampaign extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ringless_campaign', function (Blueprint $table) {
            $table->integer('voice_template_id')->nullable(); 
            $table->integer('sip_gateway_id')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ringless_campaign', function (Blueprint $table) {
            //
        });
    }
}
