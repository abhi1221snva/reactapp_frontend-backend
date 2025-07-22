<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsAiListTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_ai_list', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('campaign_id');
            $table->integer('total_leads');
            $table->string('file_name');
            $table->enum('status', array('1','0'))->default('1')->comment('1-active,0-inactive');
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
        Schema::dropIfExists('sms_ai_list');
    }
}
