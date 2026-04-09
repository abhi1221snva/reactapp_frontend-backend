<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDripV2EventsTable extends Migration
{
    public function up(): void
    {
        Schema::create('drip_v2_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('send_log_id');
            $table->enum('event_type', [
                'sent', 'delivered', 'opened', 'clicked', 'bounced',
                'dropped', 'unsubscribed', 'replied', 'failed'
            ]);
            $table->json('event_data')->nullable();
            $table->string('provider_event_id', 255)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('send_log_id');
            $table->index('event_type');
            $table->foreign('send_log_id')->references('id')->on('drip_v2_send_log')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_v2_events');
    }
}
