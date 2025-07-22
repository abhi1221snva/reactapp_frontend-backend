<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CallAnalysisLog extends Model
{
    protected $fillable = [
        'reference_id',
        'agent_id',
        'campaign_id',
        'response_data',
        'duration',
        'status',
        'error_message',
    ];

    protected $casts = [
        'response_data' => 'array',
    ];
}
