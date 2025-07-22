<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsAiTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_ai', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('number');
            $table->bigInteger('did');
            $table->text('message');
            $table->string('date');

            //$table->timestamp('date')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->enum('operator', array('nexmo','didforsale','telnyx','plivo','voxox'))->default('telnyx')->nullable();
            $table->enum('type', array('merchant','ai'))->nullable();
            $table->enum('status', array('1','0'))->default('0')->comment('1-read,0-unread');
            $table->enum('sms_type', array('incoming','outgoing'))->default('incoming');

            $table->text('json_data');


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
        Schema::dropIfExists('sms_ai');
    }
}
