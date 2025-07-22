<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateLeadReportTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('lead_report', function(Blueprint $table)
		{
			$table->integer('campaign_id');
			$table->integer('list_id');
			$table->bigInteger('lead_id');
			$table->integer('disposition_id')->nullable();
			$table->primary(['campaign_id','lead_id']);
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('lead_report');
	}

}
