<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToSmsProviders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sms_providers', function (Blueprint $table) {
            $table->string('label_name')->nullable();
            $table->string('host')->nullable();
            $table->string('sip_username')->nullable();
            $table->string('sip_password')->nullable();
            $table->string('user_extension_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sms_providers', function (Blueprint $table) {
            //
        });
    }
}
