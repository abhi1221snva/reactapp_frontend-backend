<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateUsersTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('users', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('parent_id')->default(0);
			$table->string('first_name')->nullable();
			$table->string('last_name')->nullable();
			$table->bigInteger('mobile')->nullable();
			$table->string('email')->unique();
			$table->string('password');
			$table->integer('role');
			$table->string('company_name')->nullable();
			$table->string('address_1')->nullable();
			$table->string('address_2')->nullable();
			$table->string('profile_pic')->nullable();
			$table->integer('extension');
			$table->string('rpm');
			$table->integer('vm_pin');
			$table->boolean('voicemail')->default(0);
			$table->string('voicemail_greeting')->nullable();
			$table->integer('asterisk_server_id');
			$table->boolean('voicemail_send_to_email')->default(0);
			$table->boolean('follow_me')->default(0);
			$table->string('dialpad');
			$table->string('agent_voice_id')->nullable();
			$table->boolean('cli_setting')->default(0);
			$table->boolean('cli')->default(0);
			$table->string('local_ip')->nullable();
			$table->string('public_ip')->nullable();
			$table->string('phone_status')->nullable();
			$table->boolean('status')->default(0);
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
		Schema::drop('users');
	}

}
