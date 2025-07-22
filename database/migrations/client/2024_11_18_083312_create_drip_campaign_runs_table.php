<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripCampaignRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('drip_campaign_runs', function (Blueprint $table) {
            $table->id();
            $table->integer('schedule_id'); 
            $table->integer('lead_id'); 
            $table->integer('send_type'); 
            $table->datetime('scheduled_time'); 
            $table->tinyInteger('status')->default(1); 
            $table->string('processing_id')->nullable();  
            $table->datetime('start_time')->nullable(); 
            $table->datetime('sent_time')->nullable(); 
            $table->string('currency_code');
            $table->integer('client_package_id')->nullable(); 
            $table->integer('user_id')->nullable(); 
            $table->integer('isFree')->nullable(); 
            $table->integer('charge')->nullable(); 
            $table->integer('lead_status_id'); 
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('drip_campaign_runs');
    }
}
