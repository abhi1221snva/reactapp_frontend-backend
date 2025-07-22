<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessLeadReport extends Model
{
    protected $table = "ringless_lead_report";
    protected $fillable = ['id','campaign_id','list_id','lead_id','merchant_number','cli','delivery_status'];
}