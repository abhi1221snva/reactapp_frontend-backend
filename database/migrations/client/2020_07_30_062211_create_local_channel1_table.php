<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateLocalChannel1Table extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('local_channel1', function(Blueprint $table)
		{
			$table->string('confno', 10)->nullable();
			$table->text('local_channel');
			$table->integer('campaign_id')->nullable();
			$table->integer('lead_id')->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('local_channel1');
	}

}
