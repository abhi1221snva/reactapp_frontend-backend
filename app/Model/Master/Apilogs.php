<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class ApiLogs extends Model
{
    protected $table = 'api_logs'; // Ensure this matches your table name
    protected $fillable = [
        'client_id', 'endpoint', 'lender_id', 'request_data', 'response_data', 
        'status_code', 'request_ip', 'user_agent', 'created_at','businessID','lead_id','lender_api_type'
    ];
}
