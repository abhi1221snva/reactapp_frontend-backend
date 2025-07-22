<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $table = 'api_logs'; // Ensure this matches your table name
    protected $fillable = [
        'client_id', 'endpoint', 'lender_id', 'request_data', 'response_data', 
        'status_code', 'request_ip', 'user_agent', 'created_at','businessID','lead_id'
    ];
}
