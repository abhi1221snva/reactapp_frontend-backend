<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateExtensionLiveTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('extension_live', function(Blueprint $table)
		{
			$table->integer('extension')->primary();
			$table->boolean('status')->default(0);
			$table->string('channel')->nullable();
			$table->integer('campaign_id')->nullable();
			$table->integer('lead_id')->nullable();
			$table->string('call_status')->nullable();
			$table->string('transfer_status')->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('extension_live');
	}

}
