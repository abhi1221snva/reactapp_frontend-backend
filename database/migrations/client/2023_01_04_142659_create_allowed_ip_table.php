<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAllowedIpTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('allowed_ip', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 25);
            $table->string('label', 50);
            $table->enum('is_primary', array('0','1'))->default('0'); // 0-no,1-yes
            $table->enum('status', array('0','1'))->default('0'); // 0-inactive,1-active
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
        Schema::dropIfExists('allowed_ip');
    }
}
