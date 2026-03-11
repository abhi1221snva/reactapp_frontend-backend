<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwilioSmsTable extends Migration
{
    public function up(): void
    {
        Schema::create('twilio_sms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sms_sid', 64)->unique();      // SM...
            $table->string('from_number', 20);
            $table->string('to_number', 20);
            $table->text('body');
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->enum('status', [
                'queued','sending','sent','delivered',
                'undelivered','failed','received'
            ])->default('queued');
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->decimal('price', 10, 6)->nullable();
            $table->string('price_unit', 5)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('direction');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_sms');
    }
}
