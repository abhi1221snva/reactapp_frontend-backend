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
			$table->string('mobile', 200)->nullable();
			$table->string('email')->unique();
			$table->string('password');
			$table->integer('role');
			$table->string('company_name')->nullable();
			$table->string('address_1')->nullable();
			$table->string('address_2')->nullable();
			$table->string('profile_pic')->nullable();
			$table->integer('extension');
			$table->string('rpm', 100)->nullable();
			$table->integer('vm_pin')->nullable();
			$table->boolean('voicemail')->default(0);
			$table->string('voicemail_greeting')->nullable();
			$table->integer('asterisk_server_id');
			$table->boolean('voicemail_send_to_email')->default(0);
			$table->boolean('follow_me')->default(0);
			$table->boolean('call_forward')->nullable()->default(0);
			$table->string('dialpad', 100)->nullable();
			$table->string('agent_voice_id')->nullable();
			$table->boolean('cli_setting')->default(0);
			$table->string('cli', 14)->nullable();
			$table->string('local_ip')->nullable();
			$table->string('public_ip')->nullable();
			$table->string('phone_status')->nullable();
			$table->boolean('status')->default(0);
			$table->timestamps();
			$table->integer('is_deleted')->nullable()->default(0);
			$table->string('alt_extension', 7)->nullable();
			$table->string('allowed_ip')->nullable();
			$table->string('twinning', 3)->nullable();
			$table->string('directory_name', 50)->nullable();
			$table->string('extension_type', 3)->nullable();
			$table->string('logo')->nullable();
			$table->enum('vm_drop', array('1','0'))->nullable()->default('0');
			$table->string('vm_drop_location')->default('0');
			$table->string('cnam', 20)->nullable();
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
