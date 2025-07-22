<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRinglessCdrTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ringless_cdr', function (Blueprint $table) {
            $table->increments('id');
			$table->string('type')->default('ringless_voicemail');
			$table->bigInteger('number');
            $table->bigInteger('cli');
			$table->string('channel');
			$table->integer('duration')->nullable();
			$table->integer('unit_minute')->nullable();
			$table->float('charge', 10, 0)->nullable();
			$table->timestamp('start_time')->default(DB::raw('CURRENT_TIMESTAMP'));
			$table->dateTime('end_time')->nullable();
			$table->string('call_recording')->nullable();
			$table->integer('campaign_id')->nullable();
			$table->integer('lead_id')->nullable();
			$table->integer('dnis')->nullable();
            $table->unsignedTinyInteger('isFree')->default(0);
            $table->integer('currency_code')->nullable();
            $table->integer('client_package_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('billable_minutes')->nullable();
            $table->float('billable_charge', 10, 0)->nullable();
            $table->integer('area_code')->nullable();
            $table->integer('country_code')->default(1);
            $table->enum('status', array('1','0'))->default('1')->comment('1-active,0-inactive');
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
        Schema::dropIfExists('ringless_cdr');
    }
}
