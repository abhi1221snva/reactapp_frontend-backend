<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreatePagingTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('paging', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('parent_id');
			$table->string('admin_extension', 7);
			$table->string('extension', 7);
			$table->string('page_extensions', 1000);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('paging');
	}

}
