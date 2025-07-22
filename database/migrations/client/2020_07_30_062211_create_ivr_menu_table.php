<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateIvrMenuTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('ivr_menu', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('ivr_id', 15);
			$table->string('dtmf', 5);
			$table->string('dest_type', 15);
			$table->string('dest', 15)->nullable();
			$table->integer('is_deleted')->default(0);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('ivr_menu');
	}

}
