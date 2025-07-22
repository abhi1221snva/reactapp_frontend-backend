<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToSmsAiCampaign extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sms_ai_campaign', function (Blueprint $table) {
            $table->integer('max_lead_temp')->default(100);
            $table->integer('min_lead_temp')->default(500);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sms_ai_campaign', function (Blueprint $table) {
            //
        });
    }
}
