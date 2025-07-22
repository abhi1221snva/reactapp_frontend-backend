<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRedialColumnToLeadTemp extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_temp', function (Blueprint $table) {
            $table->enum('redial', array('0','1'))->default('0'); // 0-inactive,1-active
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_temp', function (Blueprint $table) {
            //
        });
    }
}
