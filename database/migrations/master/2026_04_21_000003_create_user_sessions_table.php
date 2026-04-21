<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserSessionsTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('device_type', 30)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_active_at']);
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('user_sessions');
    }
}
