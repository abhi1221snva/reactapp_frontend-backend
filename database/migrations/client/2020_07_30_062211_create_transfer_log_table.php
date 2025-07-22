<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTransferLogTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('transfer_log', function(Blueprint $table)
		{
			$table->bigInteger('id', true)->unsigned();
			$table->integer('extension');
			$table->integer('transfer_extension');
			$table->boolean('status')->default(1);
			$table->bigInteger('number');
			$table->integer('transfer_status_id');
			$table->timestamp('start_time')->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->string('call_recording')->nullable();
			$table->string('call_recording_transfer')->nullable();
			$table->integer('campaign_id')->nullable();
			$table->integer('lead_id')->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('transfer_log');
	}

}
