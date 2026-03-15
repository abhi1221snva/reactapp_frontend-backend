<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmSmsMessagesTable extends Migration
{
    public function up()
    {
        Schema::create('crm_sms_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->enum('direction', ['inbound','outbound'])->default('outbound');
            $table->text('body');
            $table->string('from_number', 30)->nullable();
            $table->string('to_number', 30)->nullable();
            $table->enum('status', ['pending','sent','delivered','failed','received'])->default('pending');
            $table->string('twilio_sid', 100)->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamps();
            $table->index('conversation_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_sms_messages');
    }
}
