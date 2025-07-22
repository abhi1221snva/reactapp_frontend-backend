<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRvmCallbackLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('rvm_callback_logs', function (Blueprint $table) {
            $table->id();
            $table->string('caller_number', 15);
            $table->string('incoming_number', 15);
            $table->string('callback_number', 15);
            $table->integer('duration');
            $table->timestamp('start_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('end_time')->nullable();
            $table->string('call_recording')->nullable();
            $table->integer('dnis')->nullable();
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
        Schema::dropIfExists('rvm_callback_logs');
    }
}
