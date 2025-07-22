<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateUserExtensionsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('user_extensions', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('accountcode', 20)->nullable();
			$table->string('directmedia', 128)->nullable();
			$table->string('disallow', 100)->nullable()->default('all');
			$table->string('allow', 100)->nullable()->default('alaw;ulaw;gsm;g729');
			$table->enum('allowoverlap', array('yes','no'))->nullable()->default('yes');
			$table->enum('allowsubscribe', array('yes','no'))->nullable()->default('yes');
			$table->string('allowtransfer', 3)->nullable();
			$table->string('amaflags', 13)->nullable();
			$table->string('autoframing', 3)->nullable();
			$table->string('auth', 40)->nullable();
			$table->enum('buggymwi', array('yes','no'))->nullable()->default('no');
			$table->string('callgroup', 10)->nullable();
			$table->string('callerid', 80)->nullable();
			$table->string('cid_number', 40)->nullable();
			$table->string('fullname', 40)->nullable();
			$table->integer('call-limit')->nullable()->default(0);
			$table->string('callingpres', 80)->nullable();
			$table->char('canreinvite', 6)->nullable()->default('yes');
			$table->string('context', 80)->nullable();
			$table->string('callbackextension', 80)->nullable();
			$table->string('defaultip', 15)->nullable();
			$table->string('defaultuser', 128)->nullable();
			$table->string('dtmfmode', 7)->nullable();
			$table->string('encryption', 128)->nullable();
			$table->string('fromuser', 80)->nullable();
			$table->string('fromdomain', 80)->nullable();
			$table->string('fullcontact', 80)->nullable();
			$table->enum('g726nonstandard', array('yes','no'))->nullable()->default('no');
			$table->string('host', 31)->default('');
			$table->string('insecure', 20)->nullable();
			$table->string('ipaddr', 15)->default('');
			$table->char('language', 2)->nullable();
			$table->string('lastms', 20)->nullable();
			$table->string('mailbox', 50)->nullable();
			$table->integer('maxcallbitrate')->nullable()->default(384);
			$table->string('mohsuggest', 80)->nullable();
			$table->string('md5secret', 80)->nullable();
			$table->string('musiconhold', 100)->nullable();
			$table->string('name', 80)->default('')->index('name_2');
			$table->string('nat', 128)->nullable();
			$table->string('outboundproxy', 80)->nullable();
			$table->string('deny', 95)->nullable();
			$table->string('permit', 95)->nullable();
			$table->string('pickupgroup', 10)->nullable();
			$table->string('port', 5)->default('');
			$table->enum('progressinband', array('yes','no','never'))->nullable()->default('no');
			$table->enum('promiscredir', array('yes','no'))->nullable()->default('no');
			$table->char('qualify', 3)->nullable();
			$table->string('regexten', 80)->default('');
			$table->integer('regseconds')->default(0);
			$table->enum('rfc2833compensate', array('yes','no'))->nullable()->default('no');
			$table->char('rtptimeout', 3)->nullable();
			$table->char('rtpholdtimeout', 3)->nullable();
			$table->string('secret', 80)->nullable();
			$table->enum('sendrpid', array('yes','no'))->nullable()->default('yes');
			$table->string('setvar', 100)->default('');
			$table->string('subscribecontext', 80)->nullable();
			$table->string('subscribemwi', 3)->nullable();
			$table->enum('t38pt_udptl', array('yes','no'))->nullable()->default('no');
			$table->string('transport', 128)->nullable();
			$table->enum('trustrpid', array('yes','no'))->nullable()->default('no');
			$table->string('type', 6)->default('friend');
			$table->enum('useclientcode', array('yes','no'))->nullable()->default('no');
			$table->string('usereqphone', 3)->default('no');
			$table->string('username', 128)->nullable();
			$table->enum('videosupport', array('yes','no'))->nullable()->default('yes');
			$table->string('vmexten', 80)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('user_extensions');
	}

}
