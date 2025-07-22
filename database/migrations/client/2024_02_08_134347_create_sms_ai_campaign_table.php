<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsAiCampaignTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_ai_campaign', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->enum('dialing_mode', array('sms_ai'))->default('sms_ai');
            $table->enum('status', array('1','0'))->default('1')->comment('1-active,0-inactive');
            $table->string('call_ratio', 4)->default(1);
            $table->string('call_duration', 3)->default(0);
            $table->enum('caller_id', array('custom','area_code','area_code_random'))->default('custom');
            $table->bigInteger('custom_caller_id')->nullable();
            $table->unsignedTinyInteger('time_based_calling')->default(0);
            $table->time('call_time_start');
            $table->time('call_time_end');
            $table->integer('country_code')->default(1);
            $table->unsignedTinyInteger('is_deleted')->default(0);  
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
        Schema::dropIfExists('sms_ai_campaign');
    }
}
