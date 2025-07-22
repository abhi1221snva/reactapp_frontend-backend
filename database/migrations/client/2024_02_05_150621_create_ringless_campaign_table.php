<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRinglessCampaignTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ringless_campaign', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->enum('caller_id', array('custom','area_code','area_code_random'))->default('custom');
            $table->integer('custom_caller_id')->nullable();
            $table->unsignedTinyInteger('time_based_calling')->default(0);
            $table->time('call_time_start');
            $table->time('call_time_end');
            $table->enum('status', array('1','0'))->default('1')->comment('1-active,0-inactive');
            $table->unsignedTinyInteger('is_deleted')->default(0);  
            $table->dateTime('updated_at')->useCurrent()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ringless_campaign');
    }
}
