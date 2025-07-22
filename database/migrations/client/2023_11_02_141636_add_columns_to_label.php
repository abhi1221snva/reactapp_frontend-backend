<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToLabel extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('label', function (Blueprint $table) {
            $table->integer('display_order')->nullable();
            $table->enum('status', array('1','0'))->default(1)->nullable()->comment('0-no,1-yes');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('label', function (Blueprint $table) {
            //
        });
    }
}
