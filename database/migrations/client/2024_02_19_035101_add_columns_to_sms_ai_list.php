<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToSmsAiList extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sms_ai_list', function (Blueprint $table) {
            $table->enum('is_dialing', array('1','0'))->default('0')->comment('1-yes,0-no');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sms_ai_list', function (Blueprint $table) {
            //
        });
    }
}
