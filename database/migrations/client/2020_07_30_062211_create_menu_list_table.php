<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateMenuListTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('menu_list', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->integer('parent_id');
			$table->string('name');
			$table->string('url');
			$table->string('logo');
			$table->enum('status', array('1','0'));
			$table->integer('order');
			$table->integer('arrow');
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('menu_list');
	}

}
