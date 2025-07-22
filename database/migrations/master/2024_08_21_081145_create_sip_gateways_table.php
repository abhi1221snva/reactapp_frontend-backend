<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSipGatewaysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sip_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->string('sip_trunk_provider');
            $table->string('sip_trunk_name');
            $table->string('sip_host');
            $table->string('sip_trunk_password');
            $table->string('sip_trunk_context');
            $table->string('sip_twilio_sid')->nullable();
            $table->string('sip_twilio_token')->nullable();
            $table->string('sip_plivo_auth_token')->nullable();
            $table->timestamps();
        });
    }

    
    public function down()
    {
        Schema::dropIfExists('sip_gateways');
    }
}
