<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CallAnalysisSummary extends Model
{
    protected $fillable = [
        'reference_id', 'agent_id', 'campaign_id',
        'total_score', 'max_score', 'percentage',
        'agent_total_score', 'agent_max_score', 'agent_average_score',
        'lead_category_emoji', 'lead_category_desc', 'coaching_recommendation'
    ];

    public function leadScorecards()
    {
        return $this->hasMany(LeadScorecard::class, 'analysis_id');
    }

    public function agentMetrics()
    {
        return $this->hasMany(AgentPerformanceMetric::class, 'analysis_id');
    }
}
