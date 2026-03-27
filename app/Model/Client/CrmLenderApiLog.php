<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLenderApiLog extends Model
{
    public $timestamps = false;

    protected $table = 'crm_lender_api_logs';

    protected $fillable = [
        'crm_lender_api_id',
        'lead_id',
        'lender_id',
        'user_id',
        'request_url',
        'request_method',
        'request_headers',
        'request_payload',
        'response_code',
        'response_body',
        'status',
        'error_message',
        'duration_ms',
        'attempt',
        'created_at',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'response_code'   => 'integer',
        'duration_ms'     => 'integer',
        'attempt'         => 'integer',
    ];

    public function apiConfig()
    {
        return $this->belongsTo(CrmLenderAPis::class, 'crm_lender_api_id');
    }
}
