<?php
namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;

class SmsAiLeadTemp extends Model
{
    protected $table = "sms_ai_lead_temp";
    protected $fillable = ['campaign_id','list_id','lead_id'];
}