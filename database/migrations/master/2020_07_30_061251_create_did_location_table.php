<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateDidLocationTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('did_location', function(Blueprint $table)
		{
			$table->integer('id');
			$table->string('country_id', 50);
			$table->string('state_id', 50);
			$table->string('npa', 50);
			$table->string('nxx', 50);
			$table->enum('status', array('1','0'))->default('1');
			$table->integer('vender_id');
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('did_location');
	}

}
