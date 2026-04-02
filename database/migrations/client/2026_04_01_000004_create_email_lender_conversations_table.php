<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_lender_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('lender_id');
            $table->string('gmail_message_id', 255);
            $table->string('gmail_thread_id', 255)->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('from_email', 500);
            $table->string('to_email', 500)->nullable();
            $table->string('subject', 1000)->nullable();
            $table->text('body_preview')->nullable();
            $table->tinyInteger('has_attachments')->default(0);
            $table->integer('attachment_count')->default(0);
            $table->json('attachment_filenames')->nullable();
            $table->string('detected_merchant_name', 500)->nullable();
            $table->enum('detection_source', ['subject', 'body', 'both'])->nullable();
            $table->tinyInteger('offer_detected')->default(0);
            $table->json('offer_details')->nullable();
            $table->timestamp('conversation_date')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->unsignedBigInteger('note_id')->nullable();
            $table->timestamps();

            $table->unique(['gmail_message_id', 'user_id'], 'elc_message_user_unique');
            $table->index('lead_id');
            $table->index('lender_id');
            $table->index('user_id');
            $table->index('conversation_date');
            $table->index('offer_detected');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_lender_conversations');
    }
};
