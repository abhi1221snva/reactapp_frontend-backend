<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateClientServerTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('client_server', function(Blueprint $table)
		{
			$table->integer('id');
			$table->integer('client_id')->nullable();
			$table->string('ip_address')->nullable();
			$table->string('detail')->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('client_server');
	}

}
