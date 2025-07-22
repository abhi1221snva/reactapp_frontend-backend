<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateDispositionTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('disposition', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('title');
			$table->boolean('status')->default(1);
			$table->string('d_type', 10)->nullable()->default('1');
			$table->boolean('is_deleted')->nullable()->default(0);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('disposition');
	}

}
