<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripV2SendLogTable extends Migration
{
    public function up(): void
    {
        Schema::create('drip_v2_send_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('step_id');
            $table->unsignedBigInteger('lead_id');
            $table->enum('channel', ['email', 'sms']);
            $table->string('to_address', 255);
            $table->string('from_address', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('body_preview', 500)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->enum('status', ['queued', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'failed', 'unsubscribed'])->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('enrollment_id');
            $table->index('lead_id');
            $table->index('provider_message_id');
            $table->index('status');
            $table->foreign('enrollment_id')->references('id')->on('drip_v2_enrollments')->onDelete('cascade');
            $table->foreign('step_id')->references('id')->on('drip_v2_steps')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_v2_send_log');
    }
}
