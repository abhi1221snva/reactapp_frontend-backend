<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

class EmailParsedApplication extends Model
{
    protected $table = 'email_parsed_applications';

    protected $fillable = [
        'attachment_id',
        'gmail_message_id',
        'user_id',
        'business_name',
        'business_dba',
        'owner_first_name',
        'owner_last_name',
        'owner_email',
        'owner_phone',
        'owner_ssn_last4',
        'business_ein',
        'business_address',
        'business_city',
        'business_state',
        'business_zip',
        'business_type',
        'annual_revenue',
        'monthly_revenue',
        'requested_amount',
        'use_of_funds',
        'time_in_business',
        'confidence_score',
        'raw_extraction',
        'extraction_model',
        'status',
        'lead_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'raw_extraction'  => 'array',
        'annual_revenue'  => 'float',
        'monthly_revenue' => 'float',
        'requested_amount' => 'float',
        'confidence_score' => 'float',
        'attachment_id'   => 'integer',
        'user_id'         => 'integer',
        'lead_id'         => 'integer',
        'reviewed_at'     => 'datetime',
    ];

    public function attachment()
    {
        return $this->belongsTo(EmailParsedAttachment::class, 'attachment_id');
    }
}
