<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRefreshTokensTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->char('family_id', 36)->index();
            $table->timestamp('expires_at');
            $table->boolean('revoked')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked']);
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('refresh_tokens');
    }
}
