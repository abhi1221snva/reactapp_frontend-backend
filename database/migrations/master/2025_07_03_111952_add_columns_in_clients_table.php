<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsInClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->enum('ringless', array('0','1'))->default('0'); // 0-no,1-yes
            $table->enum('callchex', array('0','1'))->default('0'); // 0-no,1-yes
            $table->enum('predictive_dial', array('0','1'))->default('0'); // 0-no,1-yes

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            //
        });
    }
}
