<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateApiTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('api', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('title');
			$table->string('url');
			$table->integer('campaign_id');
			$table->enum('method', array('get','post'))->default('get');
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
		Schema::drop('api');
	}

}
