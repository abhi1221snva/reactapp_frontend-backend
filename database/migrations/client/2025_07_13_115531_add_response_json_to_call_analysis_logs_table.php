<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResponseJsonToCallAnalysisLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('call_analysis_logs', function (Blueprint $table) {
            $table->longText('response_json')->nullable()->after('campaign_id');
            //
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('call_analysis_logs', function (Blueprint $table) {
            //
        });
    }
}
