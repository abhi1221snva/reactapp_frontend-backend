<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateUserAsteriskMappingTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('user_asterisk_mapping', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('parent_id', 30)->nullable();
			$table->string('asterisk_server_id', 50)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('user_asterisk_mapping');
	}

}
