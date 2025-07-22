<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CrmLog extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_log', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('lead_id');
			$table->enum('type', array('0','1'))->default('0');
			$table->string('campaign_id')->nullable();
			$table->string('phone')->nullable();
			$table->string('url')->nullable();
			$table->string('crm_data')->nullable();
			$table->timestamp('start_time')->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->dateTime('end_time')->nullable();
		});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
		Schema::drop('crm_log');
    }
}
