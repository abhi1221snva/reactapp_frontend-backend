<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOtpCodesTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('phone_or_email', 255)->index();
            $table->string('otp_code', 10);
            $table->timestamp('expires_at');
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'verified', 'expires_at']);
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('otp_codes');
    }
}
