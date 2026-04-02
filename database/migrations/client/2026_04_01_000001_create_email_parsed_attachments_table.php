<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmailParsedAttachmentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('email_parsed_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('gmail_message_id', 255);
            $table->string('gmail_attachment_id', 500);
            $table->unsignedBigInteger('gmail_oauth_token_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('thread_id', 255)->nullable();
            $table->string('email_from', 500)->nullable();
            $table->string('email_subject', 1000)->nullable();
            $table->timestamp('email_date')->nullable();
            $table->string('filename', 500);
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('local_path', 1000)->nullable();
            $table->enum('doc_type', ['application', 'bank_statement', 'void_cheque', 'invoice', 'unknown', 'pending'])->default('pending');
            $table->decimal('classification_confidence', 5, 2)->nullable();
            $table->enum('classification_method', ['ai_vision', 'keyword', 'manual'])->nullable();
            $table->enum('parse_status', ['pending', 'parsing', 'parsed', 'failed'])->default('pending');
            $table->json('parser_response')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('linked_lead_id')->nullable();
            $table->unsignedBigInteger('linked_application_id')->nullable();
            $table->timestamps();

            $table->unique(['gmail_message_id', 'gmail_attachment_id'], 'epa_msg_att_unique');
            $table->index('user_id');
            $table->index('doc_type');
            $table->index('parse_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_parsed_attachments');
    }
}
