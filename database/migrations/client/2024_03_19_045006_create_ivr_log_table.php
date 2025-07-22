<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIvrLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ivr_log', function (Blueprint $table) {
            $table->id();
            $table->string('cli')->nullable();
            $table->string('number')->nullable();
            $table->enum('route', array('IN','OUT'));
            $table->string('ivr_id')->nullable();
            $table->string('level')->nullable();
            $table->integer('dtmf')->nullable();
            $table->integer('campaign_id')->nullable();
            $table->integer('cdr_id')->nullable();
            $table->integer('lead_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ivr_log');
    }
}
