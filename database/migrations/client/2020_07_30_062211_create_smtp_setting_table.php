<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateSmtpSettingTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('smtp_setting', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('mail_driver');
			$table->string('mail_host');
			$table->string('mail_port');
			$table->string('mail_username');
			$table->string('mail_password');
			$table->string('mail_encryption');
			$table->enum('status', array('1','0'))->default('1')->comment('1-true,0-false');
			$table->string('api_key');
			$table->integer('user_id')->default(0);
			$table->timestamps();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('smtp_setting');
	}

}
