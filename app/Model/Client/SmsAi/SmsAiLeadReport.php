<?php
namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;

class SmsAiLeadReport extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_lead_report";
    protected $fillable = ['id','campaign_id','list_id','lead_id','merchant_number','cli','delivery_status'];
}