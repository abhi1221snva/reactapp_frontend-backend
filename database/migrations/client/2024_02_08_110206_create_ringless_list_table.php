<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRinglessListTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ringless_list', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('status', array('1','0'))->default('1')->comment('1-active,0-inactive');
            $table->unsignedTinyInteger("type")->default('1');
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
        Schema::dropIfExists('ringless_list');
    }
}
