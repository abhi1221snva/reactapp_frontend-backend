<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateDestTypeListTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('dest_type_list', function(Blueprint $table)
		{
			$table->integer('id');
			$table->string('dest_type');
			$table->integer('dest_id')->default(0);
			$table->integer('is_deleted')->default(0);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('dest_type_list');
	}

}
