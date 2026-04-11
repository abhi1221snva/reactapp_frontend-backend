<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_campaigns — v2 campaign lifecycle.
 *
 * Replaces client-scoped ringless_campaign tables over time; lives in master
 * so cross-tenant reporting and global scheduler can read one source.
 */
class CreateRvmCampaignsTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_campaigns', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_api_key_id')->nullable();

            $table->string('name', 255);
            $table->text('description')->nullable();

            $table->enum('status', [
                'draft',
                'running',
                'paused',
                'completed',
                'cancelled',
            ])->default('draft');

            // Dispatch defaults — drops can override at create time.
            $table->string('caller_id', 16);
            $table->unsignedBigInteger('voice_template_id');
            $table->enum('provider_strategy', ['auto', 'pin'])->default('auto');
            $table->string('pinned_provider', 32)->nullable();

            // Compliance defaults for every drop in this campaign
            $table->time('quiet_start')->default('09:00:00');
            $table->time('quiet_end')->default('20:00:00');
            $table->boolean('respect_dnc')->default(true);

            // Pacing — leaky-bucket enforcement in RvmDispatchService
            $table->unsignedInteger('max_per_minute')->default(100);

            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            // Denormalized counters flushed from Redis every 30s
            $table->json('stats_cache')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status'], 'idx_rvm_campaigns_client_status');
            $table->index('scheduled_start', 'idx_rvm_campaigns_scheduled');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_campaigns');
    }
}
