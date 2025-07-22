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
			$table->increments('id');
			$table->string('conference_id', 200)->nullable()->unique('conference_id');
			$table->string('title')->nullable();
			$table->string('host_pin')->nullable();
			$table->string('part_pin', 200)->nullable();
			$table->string('max_part');
			$table->integer('lock');
			$table->integer('mute');
			$table->string('prompt_file')->nullable();
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
