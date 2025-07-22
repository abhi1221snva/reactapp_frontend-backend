<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateAmiLogTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('ami_log', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('admin')->nullable();
			$table->integer('campaign_id')->nullable();
			$table->integer('extension')->nullable();
			$table->string('action')->nullable();
			$table->bigInteger('mobile')->nullable();
			$table->string('request')->nullable();
			$table->string('response')->nullable();
			$table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('ami_log');
	}

}
