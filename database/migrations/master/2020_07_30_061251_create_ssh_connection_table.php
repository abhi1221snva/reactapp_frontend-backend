<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateSshConnectionTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('ssh_connection', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('ip', 30)->nullable();
			$table->string('ssh_user', 30)->nullable();
			$table->string('ssh_password', 30)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('ssh_connection');
	}

}
