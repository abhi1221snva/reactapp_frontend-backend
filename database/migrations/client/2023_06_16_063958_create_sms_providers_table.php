<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsProvidersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_providers', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('auth_id');
            $table->string('api_key');
            $table->unsignedTinyInteger("status")->default(1)->comment('1-active, 0-inactive');
            $table->enum('provider', array(
                'didforsale',
                'plivo',
            ))->nullable();
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
        Schema::dropIfExists('sms_providers');
    }
}
