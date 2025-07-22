<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateUserSettingTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('user_setting', function(Blueprint $table)
		{
			$table->integer('auto_id')->primary();
			$table->string('setting_name');
			$table->enum('type', array('Email','Sms','Logo'))->default('Email');
			$table->enum('status', array('1','0'))->default('0');
			$table->text('sender_list')->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('user_setting');
	}

}
