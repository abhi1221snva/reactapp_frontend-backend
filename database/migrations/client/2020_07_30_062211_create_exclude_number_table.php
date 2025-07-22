<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateExcludeNumberTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('exclude_number', function(Blueprint $table)
		{
			$table->bigInteger('number');
			$table->integer('campaign_id');
			$table->string('first_name');
			$table->string('last_name');
			$table->string('company_name');
			$table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->primary(['number','campaign_id']);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('exclude_number');
	}

}
