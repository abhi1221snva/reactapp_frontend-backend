<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketingCampaignRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('marketing_campaign_runs');
        Schema::create('marketing_campaign_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('schedule_id'); //marketing_campaign_schedules.id
            $table->unsignedInteger('lead_id'); //list_data.id
            $table->unsignedInteger('send_type');  // 1-email,2-sms
            $table->string('send_to');  // email/phone no
            $table->dateTime('scheduled_time');
            $table->unsignedTinyInteger("status")->default(1)->comment('1-queued,2-processing,3-sent,4-queued,5-aborted,6-failed');
            $table->string('processing_id',36)->nullable(); //UUID v4, indexed
            $table->dateTime('start_time')->nullable(); //time when sent
            $table->dateTime('sent_time')->nullable();  //time when sent
            $table->index(['scheduled_time', 'status']);
            $table->unique(['schedule_id', 'lead_id']);
            $table->index('processing_id');
            $table->foreign('schedule_id')->references('id')->on('marketing_campaign_schedules');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('marketing_campaign_runs');
    }
}
