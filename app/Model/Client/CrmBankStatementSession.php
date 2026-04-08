<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmBankStatementSession extends Model
{
    protected $table = 'crm_bank_statement_sessions';

    protected $fillable = [
        'lead_id', 'document_id', 'batch_id', 'session_id', 'file_name', 'status',
        'model_tier', 'summary_data', 'mca_analysis', 'monthly_data',
        'fraud_score', 'total_revenue', 'total_deposits', 'nsf_count',
        'error_message', 'uploaded_by', 'analyzed_at',
    ];

    protected $casts = [
        'summary_data'   => 'array',
        'mca_analysis'   => 'array',
        'monthly_data'   => 'array',
        'fraud_score'    => 'float',
        'total_revenue'  => 'float',
        'total_deposits' => 'float',
        'nsf_count'      => 'integer',
        'analyzed_at'    => 'datetime',
    ];

    public const STATUSES    = ['pending', 'processing', 'completed', 'failed'];
    public const MODEL_TIERS = ['lsc_basic', 'lsc_pro', 'lsc_max'];
}
