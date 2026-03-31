<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class LenderDocument extends Model
{
    protected $table   = 'lender_documents';
    protected $guarded = ['id'];

    protected $casts = [
        'lender_response' => 'array',
    ];

    protected $fillable = [
        'lead_id', 'business_id', 'lender_name', 'document_type', 'document_need',
        'file_path', 'original_name', 'upload_status', 'lender_response',
        'error_message', 'uploaded_by', 'uploaded_at',
    ];
}
