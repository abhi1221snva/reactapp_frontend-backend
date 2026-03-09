<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLeadApproval extends Model
{
    public $timestamps = true;
    protected $table = "crm_lead_approvals";
    protected $fillable = [
        'lead_id', 'requested_by', 'reviewed_by', 'approval_type',
        'approval_stage', 'status', 'request_note', 'review_note',
        'requested_amount', 'approved_amount', 'expires_at', 'reviewed_at',
    ];
    protected $dates = ['expires_at', 'reviewed_at'];
}
