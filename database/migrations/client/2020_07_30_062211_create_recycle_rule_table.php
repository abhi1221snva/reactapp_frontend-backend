<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateRecycleRuleTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('recycle_rule', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('campaign_id');
			$table->integer('list_id');
			$table->integer('disposition_id');
			$table->enum('day', array('sunday','monday','tuesday','wednesday','thursday','friday','saturday'));
			$table->time('time')->nullable();
			$table->integer('call_time');
			$table->boolean('is_deleted')->default(0);
			$table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('recycle_rule');
	}

}
