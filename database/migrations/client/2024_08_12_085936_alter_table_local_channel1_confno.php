<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTableLocalChannel1Confno extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('local_channel1', function (Blueprint $table) {
            DB::statement("ALTER TABLE `local_channel1` CHANGE `confno` `confno` VARCHAR(25) COLLATE latin1_swedish_ci NOT NULL");
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('local_channel1', function (Blueprint $table) {
            //
        });
    }
}
