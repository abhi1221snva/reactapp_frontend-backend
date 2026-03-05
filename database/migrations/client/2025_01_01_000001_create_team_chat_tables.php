<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTeamChatTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Team Conversations table
        Schema::create('team_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('type', ['direct', 'group'])->default('direct');
            $table->string('name', 255)->nullable()->comment('Group name, NULL for direct messages');
            $table->string('avatar', 255)->nullable()->comment('Group avatar path');
            $table->unsignedInteger('created_by')->comment('User ID who created the conversation');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('type');
            $table->index('created_by');
        });

        // Team Conversation Participants table
        Schema::create('team_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedInteger('user_id')->comment('User ID from master.users');
            $table->enum('role', ['admin', 'member'])->default('member')->comment('Role in group');
            $table->string('nickname', 100)->nullable()->comment('Nickname in this conversation');
            $table->boolean('is_muted')->default(false);
            $table->unsignedBigInteger('last_read_message_id')->nullable()->comment('Last message user has read');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unique(['conversation_id', 'user_id'], 'unique_participant');
            $table->index('user_id');
            $table->index('conversation_id');

            $table->foreign('conversation_id')
                  ->references('id')
                  ->on('team_conversations')
                  ->onDelete('cascade');
        });

        // Team Messages table
        Schema::create('team_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedInteger('sender_id')->comment('User ID from master.users');
            $table->enum('message_type', ['text', 'image', 'file', 'system'])->default('text');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable()->comment('Additional message data');
            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('sender_id');
            $table->index('created_at');

            $table->foreign('conversation_id')
                  ->references('id')
                  ->on('team_conversations')
                  ->onDelete('cascade');
        });

        // Team Message Attachments table
        Schema::create('team_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('file_path', 500);
            $table->string('file_type', 100);
            $table->unsignedInteger('file_size')->comment('Size in bytes');
            $table->string('mime_type', 100)->nullable();
            $table->string('thumbnail_path', 500)->nullable()->comment('For images');
            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id');

            $table->foreign('message_id')
                  ->references('id')
                  ->on('team_messages')
                  ->onDelete('cascade');
        });

        // Team Message Read Receipts table
        Schema::create('team_message_read_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedInteger('user_id');
            $table->timestamp('read_at')->useCurrent();

            $table->unique(['message_id', 'user_id'], 'unique_receipt');
            $table->index('message_id');
            $table->index('user_id');

            $table->foreign('message_id')
                  ->references('id')
                  ->on('team_messages')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('team_message_read_receipts');
        Schema::dropIfExists('team_message_attachments');
        Schema::dropIfExists('team_messages');
        Schema::dropIfExists('team_conversation_participants');
        Schema::dropIfExists('team_conversations');
    }
}
