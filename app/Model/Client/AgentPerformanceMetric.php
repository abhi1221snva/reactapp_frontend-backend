<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class AgentPerformanceMetric extends Model
{
    protected $fillable = [
        'analysis_id', 'category', 'score', 'score_display', 'notes'
    ];

    public function analysis()
    {
        return $this->belongsTo(CallAnalysisSummary::class, 'analysis_id');
    }
}
