<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInboundCallPopupTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('inbound_call_popup', function (Blueprint $table) {
            $table->id();
            $table->string('inbound_number', 12);
            $table->integer('parent_id');
            $table->integer('extension');
            $table->enum('status', array('1','0'))->nullable()->default('1');
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
        Schema::dropIfExists('inbound_call_popup');
    }
}
