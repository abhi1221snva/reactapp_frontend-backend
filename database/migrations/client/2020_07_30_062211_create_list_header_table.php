<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateListHeaderTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('list_header', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('list_id');
			$table->string('header');
			$table->string('column_name');
			$table->integer('label_id')->nullable();
			$table->boolean('is_search')->default(0);
			$table->boolean('is_dialing')->default(0);
			$table->boolean('is_visible')->default(1);
			$table->boolean('is_editable')->default(0);
			$table->boolean('is_deleted')->default(0);
			$table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('list_header');
	}

}
