<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripCampaignSchedulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('drip_campaign_schedules', function (Blueprint $table) {
            $table->id();
            $table->integer('campaign_id'); 
            $table->integer('list_id'); 
            $table->string('list_column_name');         
            $table->tinyInteger('send'); 
            $table->integer('email_setting_id')->nullable(); 
            $table->bigInteger('email_template_id')->nullable(); 
            $table->integer('sms_setting_id')->nullable(); 
            $table->integer('sms_template_id')->nullable(); 
            $table->integer('sms_country_code')->nullable(); 
            $table->datetime('run_time'); 
            $table->tinyInteger('status')->default(1); 
            $table->string('processing_id')->nullable(); 
            $table->integer('created_by'); 
            $table->datetime('complete_time')->nullable(); 
            $table->integer('scheduled_count')->default(0); 
            $table->integer('send_count')->default(0); 
            $table->integer('failed_count')->default(0); 
            $table->integer('last_lead_id')->default(0); 
            $table->integer('lead_status_id'); 
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
        Schema::dropIfExists('drip_campaign_schedules');
    }
}
