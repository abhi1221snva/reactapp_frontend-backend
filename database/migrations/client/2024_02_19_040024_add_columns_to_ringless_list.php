<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToRinglessList extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ringless_list', function (Blueprint $table) {
            $table->integer('campaign_id');
            $table->integer('total_leads');
            $table->string('file_name');
            $table->enum('is_dialing', array('1','0'))->default('0')->comment('1-yes,0-no');
            $table->dropColumn('type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ringless_list', function (Blueprint $table) {
            //
        });
    }
}
