<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateConferencingTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('conferencing', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('title');
			$table->string('conference_id');
			$table->string('host_pin', 6);
			$table->string('part_pin', 6);
			$table->string('max_part', 6);
			$table->string('locked', 6);
			$table->string('mute', 6);
			$table->string('prompt_file');
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('conferencing');
	}

}
