<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLenderAPis extends Model
{
    public $timestamps = true;

    protected $table = 'crm_lender_apis';

    protected $fillable = [
        // ── Legacy fields (kept for backward compatibility) ────────────────────
        'username',
        'password',
        'api_key',
        'bittyadvance_label',
        'url',
        'type',
        'crm_lender_id',
        'sales_rep_email',
        'partner_api_key',
        'auth_url',
        'client_id',

        // ── New scalable fields ────────────────────────────────────────────────
        'api_name',
        'auth_type',         // bearer|basic|api_key|oauth2|none
        'auth_credentials',  // JSON: { token | username+password | key | client_id+secret }
        'base_url',
        'endpoint_path',
        'request_method',    // GET|POST|PUT|PATCH
        'default_headers',   // JSON: { "Content-Type": "application/json" }
        'payload_mapping',   // JSON: { "crm_field_key": "lender.json.path" }
        'response_mapping',  // JSON: { "id_field": "data.id", "status_field": "data.status" }
        'retry_attempts',
        'timeout_seconds',
        'status',
        'notes',

        // ── Re-submission + document upload config (2026-03-28) ───────────────
        'required_fields',
        'resubmit_method',
        'resubmit_endpoint_path',
        'document_upload_enabled',
        'document_upload_endpoint',
        'document_upload_method',
        'document_upload_field_name',
    ];

    protected $casts = [
        'auth_credentials' => 'array',
        'default_headers'  => 'array',
        'payload_mapping'  => 'array',
        'response_mapping' => 'array',
        'status'                  => 'boolean',
        'retry_attempts'          => 'integer',
        'timeout_seconds'         => 'integer',
        'document_upload_enabled' => 'boolean',
        'required_fields'         => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function logs()
    {
        return $this->hasMany(CrmLenderApiLog::class, 'crm_lender_api_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Whether this config has the new-style fields populated.
     * Used during the migration period to decide which code path to use.
     */
    public function isNewStyle(): bool
    {
        return !empty($this->auth_type) && $this->auth_type !== 'none'
            || !empty($this->base_url)
            || !empty($this->payload_mapping);
    }

    /**
     * Resolve the full endpoint URL.
     */
    public function fullUrl(): string
    {
        $base = rtrim($this->base_url ?: $this->url ?: '', '/');
        $path = ltrim($this->endpoint_path ?? '', '/');
        return $path ? "{$base}/{$path}" : $base;
    }
}
