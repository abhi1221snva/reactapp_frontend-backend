<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRvmCdrLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('rvm_cdr_log', function (Blueprint $table) {
            $table->id();
            $table->string('cli', 15);
            $table->string('phone', 15);
            $table->string('api_token', 255);
            $table->string('api_client_name', 255);
            $table->string('sip_trunk_name', 255)->nullable();
            $table->string('sip_trunk_provider', 255)->nullable();
            $table->integer('rvm_domain_id')->nullable();
            $table->integer('sip_gateway_id')->nullable();
            $table->integer('voicemail_drop_log_id')->nullable();
            $table->enum('api_type', array('live','testing'))->default('live');
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
        Schema::dropIfExists('rvm_cdr_log');
    }
}
