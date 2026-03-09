<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLeadStatusHistory extends Model
{
    public $timestamps = false;
    protected $table = "crm_lead_status_history";
    protected $fillable = [
        'lead_id', 'user_id', 'from_status', 'to_status',
        'from_assigned_to', 'to_assigned_to', 'from_lead_type',
        'to_lead_type', 'reason', 'triggered_by', 'created_at',
    ];
    protected $dates = ['created_at'];
}
