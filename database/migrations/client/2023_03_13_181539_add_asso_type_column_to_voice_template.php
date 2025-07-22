<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAssoTypeColumnToVoiceTemplate extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('voice_templete', function (Blueprint $table) {
              $table->integer('associate_type')->length(10)->default(3);
              $table->string('associate_id',50)->default(73);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('voice_templete', function (Blueprint $table) {
            //
        });
    }
}
