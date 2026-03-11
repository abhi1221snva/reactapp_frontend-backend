<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Pre-aggregated daily metrics snapshot.
 * Populated by MetricsAggregationJob.
 */
class DailyMetricSnapshot extends Model
{
    protected $table = 'daily_metric_snapshots';

    protected $fillable = [
        'snapshot_date',
        'campaign_id',
        'agent_id',
        'granularity',
        'total_calls',
        'answered_calls',
        'missed_calls',
        'failed_calls',
        'inbound_calls',
        'outbound_calls',
        'total_talk_time',
        'avg_talk_time',
        'max_talk_time',
        'answer_rate',
        'conversion_rate',
        'dispositioned_calls',
        'leads_contacted',
        'leads_converted',
    ];

    protected $casts = [
        'snapshot_date'   => 'date',
        'answer_rate'     => 'float',
        'conversion_rate' => 'float',
    ];
}
