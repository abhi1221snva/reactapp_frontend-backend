<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreatePackagesTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('packages', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('package_id', 4)->nullable();
			$table->string('cost_per_user', 5)->nullable();
			$table->string('rate_per_minute', 9)->nullable();
			$table->string('rate_per_did', 5)->nullable();
			$table->string('vm_rate_per_exten', 2)->nullable();
			$table->string('callrecording_rate_per_exten', 2)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('packages');
	}

}
