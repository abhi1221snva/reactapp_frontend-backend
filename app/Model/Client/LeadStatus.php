<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class LeadStatus extends Model
{
    public $timestamps = true;
    protected $table = "crm_lead_status";
    protected $fillable = ['title','lead_title_url','status','webhook_status','webhook_url','webhook_token','webhook_method'];

}
