<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateSmsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('sms', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('extension')->nullable();
			$table->bigInteger('number')->nullable();
			$table->bigInteger('did')->nullable();
			$table->string('message', 1000)->nullable();
			$table->timestamp('date')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->enum('operator', array('nexmo','didforsale'))->nullable();
			$table->enum('type', array('incoming','outgoing'))->nullable();
			$table->enum('status', array('1','0'))->default('0')->comment('1-read,0-unread');
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('sms');
	}

}
