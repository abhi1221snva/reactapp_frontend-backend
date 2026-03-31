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
        'submission_type',
        'response_status',
        'notes',
        'response_note',
        'submitted_by',
        'submitted_at',
        'response_received_at',
        'api_error',
        'error_messages',
        'doc_upload_status',
        'doc_upload_notes',
    ];

    protected $casts = [
        'submitted_at'          => 'datetime',
        'response_received_at'  => 'datetime',
        'error_messages'        => 'array',
    ];

    public const SUBMISSION_TYPES = ['normal', 'api'];

    public const SUBMISSION_STATUSES = [
        'pending', 'submitted', 'failed', 'partial', 'viewed', 'approved', 'declined', 'no_response',
    ];

    public const DOC_UPLOAD_STATUSES = ['none', 'success', 'partial', 'failed'];

    public const RESPONSE_STATUSES = [
        'pending', 'approved', 'declined', 'needs_documents', 'no_response',
    ];
}
