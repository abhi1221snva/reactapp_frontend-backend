<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateLineDetailTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('line_detail', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('extension');
			$table->enum('route', array('IN','OUT'));
			$table->enum('type', array('manual','dialer'))->default('dialer');
			$table->bigInteger('number');
			$table->string('channel');
			$table->timestamp('start_time')->default(DB::raw('CURRENT_TIMESTAMP'));
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
		Schema::drop('line_detail');
	}

}
