<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateIvrTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('ivr', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('ivr_id', 25);
			$table->string('ann_id', 25);
			$table->string('ivr_desc', 50);
			$table->string('language', 255);
			$table->string('voice_name', 255);

		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('ivr');
	}

}
