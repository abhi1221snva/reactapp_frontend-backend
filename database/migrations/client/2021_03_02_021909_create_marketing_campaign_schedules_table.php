<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketingCampaignSchedulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('marketting_campaign');
        Schema::dropIfExists('marketing_campaign_schedules');
        Schema::create('marketing_campaign_schedules', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('campaign_id');
            $table->unsignedInteger('list_id');
            $table->string('list_column_name',12);
            $table->unsignedTinyInteger('send');    // 1-email, 2-sms
            $table->integer('email_setting_id')->nullable();
            $table->unsignedBigInteger('email_template_id')->nullable();
            $table->integer('sms_setting_id')->nullable();
            $table->integer('sms_template_id')->nullable();
            $table->datetime('run_time');
            $table->unsignedTinyInteger("status")->default(1)->comment('1-planned,2-processing,3-failed,4-queued,5-executing,6-completed,7-aborted');
            //1-planned,2-processing,3-failed,4-queued,5-executing,6-completed,7-aborted
            $table->string('processing_id',36)->nullable();
            $table->unsignedInteger('created_by');   //ref from users
            $table->datetime('complete_time')->nullable();  //Time schedule completed
            $table->unsignedInteger('scheduled_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('last_lead_id')->default(0);
            $table->timestamps();
        });

        Schema::table('marketing_campaign_schedules', function (Blueprint $table) {
            $table->foreign('campaign_id')->references('id')->on('marketing_campaigns');
            $table->foreign('list_id')->references('id')->on('list');
            $table->foreign('email_setting_id')->references('id')->on('smtp_setting');
            $table->foreign('email_template_id')->references('id')->on('email_templates');
            $table->foreign('sms_setting_id')->references('id')->on('did');
            $table->foreign('sms_template_id')->references('templete_id')->on('sms_templete');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('marketing_campaign_schedules');
    }
}
