<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLenderSubmission extends Model
{
    protected $table = 'crm_lender_submissions';

    protected $fillable = [
        'lead_id',
        'lender_id',
        'lender_name',
        'lender_email',
        'application_pdf',
        'submission_status',
        'response_status',
        'notes',
        'response_note',
        'submitted_by',
        'submitted_at',
        'response_received_at',
    ];

    protected $casts = [
        'submitted_at'          => 'datetime',
        'response_received_at'  => 'datetime',
    ];

    public const SUBMISSION_STATUSES = [
        'pending', 'submitted', 'viewed', 'approved', 'declined', 'no_response',
    ];

    public const RESPONSE_STATUSES = [
        'pending', 'approved', 'declined', 'needs_documents', 'no_response',
    ];
}
