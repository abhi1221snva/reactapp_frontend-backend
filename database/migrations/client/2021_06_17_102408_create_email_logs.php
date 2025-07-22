<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmailLogs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('senderType', 10)->comment('system,campaign,user');
            $table->integer('user_id')->comment('Ref from master.user.id')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('from');
            $table->string('to');
            $table->text('subject');
            $table->text('body');
            $table->decimal('charge', 8,4)->unsigned();
            $table->integer('client_package_id')->comment('Reference from master.permissions.client_package_id')->nullable();
            $table->unsignedTinyInteger("isFree")->default(0)->comment('0–No, 1-Yes');
            $table->string('currency_code', 3)->comment('ISO 4217');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('email_logs');
    }
}
