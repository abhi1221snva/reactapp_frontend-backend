<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVoipConfigurationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('voip_configuration', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_extension_id');
            $table->string('trunk_id', true);
            $table->string('disallow', 100)->nullable()->default('all');
            $table->string('allow', 100)->nullable()->default('alaw;ulaw;gsm;g729');
            $table->string('context', 80)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('host', 255)->nullable();
            $table->string('username', 128)->nullable();
            $table->string('secret', 80)->nullable();
            $table->string('prefix', 20)->nullable();
            $table->string('nat', 128)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('voip_configuration');
    }
}
