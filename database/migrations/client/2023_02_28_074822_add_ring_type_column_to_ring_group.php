<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRingTypeColumnToRingGroup extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ring_group', function (Blueprint $table) {
            $table->enum('ring_type', array('1','2','3'))->default('1'); // 1-ring all,2-sequnce,3-round ron=bin
            $table->string('intervals')->nullable();
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
        Schema::table('ring_group', function (Blueprint $table) {
            //
        });
    }
}
