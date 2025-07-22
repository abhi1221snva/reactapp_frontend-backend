<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToRinglessCampaignTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ringless_campaign', function (Blueprint $table) {
            $table->string('call_ratio', 4)->default(1);
            $table->string('call_duration', 3)->default(0);
            $table->enum('dialing_mode', array('ringless_voicemail'))->default('ringless_voicemail');
            $table->timestamp('last_time_cron_run')->default(DB::raw('CURRENT_TIMESTAMP'));
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
        Schema::table('ringless_campaign', function (Blueprint $table) {
            //
        });
    }
}
