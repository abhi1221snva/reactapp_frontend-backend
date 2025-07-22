<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateExtensionGroupMapTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('extension_group_map', function(Blueprint $table)
		{
			$table->integer('extension');
			$table->integer('group_id');
			$table->boolean('is_deleted')->default(0);
			$table->primary(['extension','group_id']);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('extension_group_map');
	}

}
