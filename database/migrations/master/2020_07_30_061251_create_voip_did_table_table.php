<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateVoipDidTableTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('voip_did_table', function(Blueprint $table)
		{
			$table->string('caller_id', 13)->nullable();
			$table->string('area_code', 3)->nullable();
			$table->string('account_num', 10)->nullable();
			$table->string('extension', 450)->nullable();
			$table->string('dest_type', 10)->nullable();
			$table->string('dest', 25)->nullable();
			$table->string('dest_prefix', 8)->nullable();
			$table->string('voicemail_id', 25)->nullable();
			$table->string('owner', 1)->nullable();
			$table->string('ingroup', 3)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('voip_did_table');
	}

}
