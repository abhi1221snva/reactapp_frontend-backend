<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateDidTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('did', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('cli', 13)->nullable();
			$table->string('cnam', 13)->nullable();
			$table->string('area_code', 3)->nullable();
			$table->string('extension', 50)->nullable();
			$table->string('dest_type', 10)->nullable();
			$table->string('operator', 25)->nullable();
			$table->string('ivr_id')->nullable();
			$table->string('conf_id')->nullable();
			$table->string('forward_number')->nullable();
			$table->string('dest_prefix', 8)->nullable();
			$table->string('voicemail_id', 25)->nullable();
			$table->string('queue_id')->nullable();
			$table->string('ingroup', 3)->nullable();
			$table->string('default_did', 2)->nullable();
			$table->string('voice')->nullable();
			$table->string('fax')->nullable();
			$table->string('sms')->nullable();
			$table->string('sms_phone')->nullable();
			$table->string('sms_email')->nullable();
			$table->string('sms_url')->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('did');
	}

}
