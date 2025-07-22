<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateCampaignTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('campaign', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('title');
			$table->string('description')->nullable();
			$table->boolean('status')->default(1);
			$table->boolean('is_deleted')->default(0);
			$table->enum('caller_id', array('custom','area_code'))->default('area_code');
			$table->bigInteger('custom_caller_id')->nullable();
			$table->boolean('time_based_calling')->default(0);
			$table->time('call_time_start')->nullable();
			$table->time('call_time_end')->nullable();
			$table->enum('dial_mode', array('preview_and_dial','power_dial','super_power_dial','predictive_dial'))->default('super_power_dial');
			$table->integer('group_id');
			$table->integer('max_lead_temp')->default(100);
			$table->integer('min_lead_temp')->default(500);
			$table->boolean('api')->default(0);
			$table->boolean('send_report')->default(0);
			$table->boolean('campaign')->default(0);
			$table->string('send_crm', 2)->nullable()->default('1');
			$table->string('email', 2)->nullable()->default('1');
			$table->string('sms', 2)->nullable()->default('1');
			$table->timestamp('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('campaign');
	}

}
