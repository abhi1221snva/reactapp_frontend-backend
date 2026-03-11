<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-aggregated daily metrics table.
 * Populated by MetricsAggregationJob every 15 minutes.
 * Enables sub-100ms dashboard responses by avoiding expensive CDR queries.
 */
class CreateDailyMetricSnapshotsTable extends Migration
{
    public function up()
    {
        Schema::create('daily_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->index();

            // NULL = overall; set = campaign-level or agent-level
            $table->unsignedInteger('campaign_id')->nullable()->index();
            $table->unsignedInteger('agent_id')->nullable()->index();

            // Aggregation level
            $table->string('granularity', 20)->default('day')->index();
            // Values: 'day' | 'campaign' | 'agent'

            // Call volume
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('answered_calls')->default(0);
            $table->unsignedInteger('missed_calls')->default(0);
            $table->unsignedInteger('failed_calls')->default(0);
            $table->unsignedInteger('inbound_calls')->default(0);
            $table->unsignedInteger('outbound_calls')->default(0);

            // Duration (seconds)
            $table->unsignedBigInteger('total_talk_time')->default(0);
            $table->unsignedInteger('avg_talk_time')->default(0);
            $table->unsignedInteger('max_talk_time')->default(0);

            // Quality
            $table->decimal('answer_rate', 5, 2)->default(0.00);
            $table->decimal('conversion_rate', 5, 2)->default(0.00);
            $table->unsignedInteger('dispositioned_calls')->default(0);

            // Agent-specific
            $table->unsignedInteger('leads_contacted')->default(0);
            $table->unsignedInteger('leads_converted')->default(0);

            $table->timestamps();

            // Unique constraint prevents duplicate snapshots
            $table->unique(
                ['snapshot_date', 'campaign_id', 'agent_id', 'granularity'],
                'uq_daily_snapshot'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('daily_metric_snapshots');
    }
}
