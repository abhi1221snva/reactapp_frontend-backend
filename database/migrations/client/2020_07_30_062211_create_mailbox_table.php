<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateMailboxTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('mailbox', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('ani');
			$table->string('vm_file_location');
			$table->string('status');
			$table->string('extension');
			$table->timestamp('date_time')->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->string('vm_file', 150)->nullable();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('mailbox');
	}

}
