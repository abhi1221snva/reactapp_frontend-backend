<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateMysqlConnectionTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('mysql_connection', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('client_id', 50)->nullable();
			$table->string('ip', 30)->nullable();
			$table->string('password', 30)->nullable();
			$table->string('db_name', 30)->nullable();
			$table->string('db_user', 30)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('mysql_connection');
	}

}
