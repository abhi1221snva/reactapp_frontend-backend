<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class LeadSourceLog extends Model
{
    public $timestamps = true;
    protected $table = "crm_lead_source_log";
    protected $fillable = ['lead_id','lead_source_url'];

}
