<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateAsteriskServerTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('asterisk_server', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('host');
			$table->string('user');
			$table->string('secret');
			$table->string('trunk');
			$table->boolean('status')->default(1);
			$table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('asterisk_server');
	}

}
