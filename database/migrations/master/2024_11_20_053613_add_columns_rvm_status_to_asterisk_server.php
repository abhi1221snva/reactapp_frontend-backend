<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsRvmStatusToAsteriskServer extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('asterisk_server', function (Blueprint $table) {
            $table->enum('rvm_status', array('0','1'))->default('0'); // 1-yes,0-no
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('asterisk_server', function (Blueprint $table) {
            //
        });
    }
}
