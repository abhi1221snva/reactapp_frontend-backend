<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVoiceTempletesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('voice_templete', function (Blueprint $table) {
            $table->integer('templete_id', true);
            $table->string('templete_name');
            $table->text('templete_desc', 65535);
            $table->text('language', 255);
            $table->text('voice_name', 255);

            $table->enum('is_deleted', array('1','0'))->default('1')->comment('1- on ,0-off');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('voice_templete');
    }
}
