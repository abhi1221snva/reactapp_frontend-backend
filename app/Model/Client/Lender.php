<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class Lender extends Model
{
    public $timestamps = true;

    protected $table = 'crm_lender';

    protected $guarded = ['id'];

    protected $fillable = [
        // ── Lender profile ───────────────────────────────────────────────────
        'lender_name', 'email', 'secondary_email', 'secondary_email2',
        'secondary_email3', 'secondary_email4',
        'contact_person', 'phone', 'status', 'address', 'state', 'city',
        'industry', 'guideline_state', 'guideline_file', 'notes',
        'min_avg_revenue', 'min_monthly_deposit', 'max_mca_payoff_amount',
        'loc', 'ownership_percentage', 'factor_rate',
        'prohibited_industry', 'restricted_industry_note', 'restricted_state_note',
        'lender_api_type', 'api_status',

        // ── API config (merged from crm_lender_apis) ────────────────────────
        'api_username', 'api_password', 'api_key', 'api_url',
        'sales_rep_email', 'partner_api_key', 'api_client_id', 'auth_url',
        'api_name', 'auth_type', 'auth_credentials', 'base_url', 'endpoint_path',
        'request_method', 'default_headers', 'payload_mapping', 'response_mapping',
        'required_fields', 'retry_attempts', 'timeout_seconds', 'api_notes',
        'resubmit_method', 'resubmit_endpoint_path',
        'document_upload_enabled', 'document_upload_endpoint',
        'document_upload_method', 'document_upload_field_name',
    ];

    protected $casts = [
        'auth_credentials'        => 'array',
        'default_headers'         => 'array',
        'payload_mapping'         => 'array',
        'response_mapping'        => 'array',
        'required_fields'         => 'array',
        'document_upload_enabled' => 'boolean',
        'retry_attempts'          => 'integer',
        'timeout_seconds'         => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function crmSendLeadToLender()
    {
        return $this->hasMany(CrmSendLeadToLender::class, 'lender_id');
    }

    public function apiLogs()
    {
        return $this->hasMany(CrmLenderApiLog::class, 'lender_id');
    }

    // ── Helpers (ported from CrmLenderAPis) ──────────────────────────────────

    /**
     * Whether this lender has new-style API config populated.
     */
    public function isNewStyle(): bool
    {
        return !empty($this->auth_type) && $this->auth_type !== 'none'
            || !empty($this->base_url)
            || !empty($this->payload_mapping);
    }

    /**
     * Resolve the full API endpoint URL.
     */
    public function fullUrl(): string
    {
        $base = rtrim($this->base_url ?: $this->api_url ?: '', '/');
        $path = ltrim($this->endpoint_path ?? '', '/');
        return $path ? "{$base}/{$path}" : $base;
    }
}
