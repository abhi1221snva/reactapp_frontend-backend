<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class LenderApplication extends Model
{
    protected $table    = 'lender_applications';
    protected $guarded  = ['id'];

    protected $casts = [
        'raw_response' => 'array',
    ];

    protected $fillable = [
        'lead_id', 'lender_name', 'business_id', 'application_number',
        'external_customer_id', 'submission_type', 'status', 'status_note',
        'raw_response', 'submitted_by',
    ];
}
