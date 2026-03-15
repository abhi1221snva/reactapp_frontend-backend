<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class CrmAutomationLog extends Model
{
    protected $table = 'crm_automation_logs';
    protected $fillable = ['automation_id','lead_id','status','result','error_message'];
    protected $casts = ['result'=>'array'];
}
