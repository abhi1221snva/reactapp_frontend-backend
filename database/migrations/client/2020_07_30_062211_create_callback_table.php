<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateCallbackTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('callback', function(Blueprint $table)
		{
			$table->bigInteger('cdr_id')->primary();
			$table->integer('extension');
			$table->integer('campaign_id');
			$table->bigInteger('lead_id');
			$table->timestamp('callback_time')->default(DB::raw('CURRENT_TIMESTAMP'));
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('callback');
	}

}
