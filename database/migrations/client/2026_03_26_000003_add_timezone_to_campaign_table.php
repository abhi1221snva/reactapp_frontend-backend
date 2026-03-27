<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTimezoneToCampaignTable extends Migration
{
    public function up()
    {
        Schema::table('campaign', function (Blueprint $table) {
            $table->string('timezone', 64)->default('America/New_York')->after('call_time_end');
        });
    }

    public function down()
    {
        Schema::table('campaign', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
}
