<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gmail_ai_analysis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('gmail_message_id', 100);
            $table->string('summary', 500)->nullable();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->string('category', 50)->nullable();
            $table->string('urgency_reason', 255)->nullable();
            $table->json('suggested_actions')->nullable();
            $table->text('suggested_reply')->nullable();
            $table->string('sentiment', 20)->nullable();
            $table->json('key_points')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'gmail_message_id']);
            $table->index('priority');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_ai_analysis');
    }
};
