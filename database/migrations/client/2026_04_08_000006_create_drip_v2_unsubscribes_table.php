<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripV2UnsubscribesTable extends Migration
{
    public function up(): void
    {
        Schema::create('drip_v2_unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->enum('channel', ['email', 'sms', 'both'])->default('both');
            $table->string('reason', 255)->nullable();
            $table->enum('source', ['link', 'reply', 'manual', 'webhook'])->default('link');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['lead_id', 'channel']);
            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_v2_unsubscribes');
    }
}
