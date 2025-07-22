<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateUserPackageTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('user_package', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('package_id', 2)->nullable();
			$table->string('voicemail', 2)->nullable();
			$table->string('call_recording', 2)->nullable();
			$table->boolean('dnc')->nullable()->default(0);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('user_package');
	}

}
