<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    public $timestamps = true;
    protected $table = "crm_lead_source";
    protected $fillable = ['source_title','url','status','webhook_secret','notify_email','notify_sms','notify_user_ids'];

    protected $casts = [
        'notify_email'    => 'boolean',
        'notify_sms'      => 'boolean',
        'notify_user_ids' => 'array',
    ];
    

}
