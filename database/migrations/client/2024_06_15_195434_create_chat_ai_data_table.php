<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChatAiDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('chat_ai_data', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->string('date');

            $table->enum('type', array('merchant','ai'))->nullable();
            $table->enum('status', array('1','0'))->default('0')->comment('1-read,0-unread');
            $table->enum('sms_type', array('incoming','outgoing'))->default('incoming');

            $table->text('json_data');

            $table->string('customer_id');




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
        Schema::dropIfExists('chat_ai_data');
    }
}
