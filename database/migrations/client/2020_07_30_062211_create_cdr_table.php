<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateCdrTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('cdr', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('extension');
			$table->enum('route', array('IN','OUT'));
			$table->enum('type', array('manual','dialer'))->default('dialer');
			$table->bigInteger('number');
			$table->string('channel');
			$table->integer('duration')->nullable();
			$table->integer('unit_minute')->nullable();
			$table->float('charge', 10, 0)->nullable();
			$table->timestamp('start_time')->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->dateTime('end_time')->nullable();
			$table->string('call_recording')->nullable();
			$table->integer('campaign_id')->nullable();
			$table->integer('disposition_id')->nullable();
			$table->integer('lead_id')->nullable();
			$table->integer('dnis')->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('cdr');
	}

}
