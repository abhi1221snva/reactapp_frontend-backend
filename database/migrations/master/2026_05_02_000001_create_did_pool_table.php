<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDidPoolTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('did_pool', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('phone_number', 20)->unique()->comment('E.164 format, e.g. +12125551234');
            $table->enum('status', ['free', 'assigned', 'reserved', 'blocked', 'cooldown'])
                  ->default('free');
            $table->unsignedInteger('assigned_client_id')->nullable();
            $table->string('provider', 30)->nullable()->comment('twilio, plivo, telnyx, manual');
            $table->string('provider_sid', 80)->nullable()->comment('Provider-specific identifier');
            $table->string('area_code', 10)->nullable();
            $table->string('country_code', 5)->default('US');
            $table->string('number_type', 20)->default('local')->comment('local, toll_free, mobile');
            $table->json('capabilities')->nullable()->comment('{"voice":true,"sms":true,"mms":false}');
            $table->string('assignment_type', 20)->nullable()->comment('trial, manual');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('cooldown_until')->nullable()->comment('Cannot reassign before this time');
            $table->string('blocked_reason', 255)->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable()->comment('Admin user ID who blocked');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('assigned_client_id');
            $table->index('area_code');
            $table->index(['status', 'cooldown_until']);
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('did_pool');
    }
}
