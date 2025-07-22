<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class SmsAiReport extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_report";
    protected $fillable = ['report_data','time_period_from','time_period_to'];

}

