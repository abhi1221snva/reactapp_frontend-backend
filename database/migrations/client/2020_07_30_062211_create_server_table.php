<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateServerTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('server', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('host', 50)->nullable();
			$table->string('username', 50)->nullable();
			$table->string('password', 100)->nullable();
			$table->string('port', 50)->nullable();
			$table->string('database_name', 50)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('server');
	}

}
