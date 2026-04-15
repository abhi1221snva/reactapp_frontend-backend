<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign lead queue for sequential click-to-call dialer.
 * Tracks which leads are queued/called for a campaign run.
 */
class CreateCampaignLeadQueueTable extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_lead_queue', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('campaign_id')->index();
            $table->unsignedInteger('lead_id')->index();
            $table->enum('status', [
                'pending',    // waiting to be dialed
                'calling',    // AMI originate fired, waiting for agent answer
                'completed',  // call finished (any outcome)
                'failed',     // max retries exceeded
                'skipped',    // DNC or removed
            ])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'lead_id']);
            $table->index(['campaign_id', 'status', 'sort_order']);
            $table->index(['campaign_id', 'status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_lead_queue');
    }
}
