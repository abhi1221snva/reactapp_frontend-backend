<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRvmCallbackConfigurationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('rvm_callback_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('cli', 15);
            $table->string('callback_number', 15);
            $table->integer('sip_gateway_id')->nullable();
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
        Schema::dropIfExists('rvm_callback_configuration');
    }
}
