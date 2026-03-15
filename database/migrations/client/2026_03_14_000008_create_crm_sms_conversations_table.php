<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmSmsConversationsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_sms_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('lead_phone', 30);
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->enum('status', ['open','closed','archived'])->default('open');
            $table->timestamps();
            $table->index('lead_id');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_sms_conversations');
    }
}
