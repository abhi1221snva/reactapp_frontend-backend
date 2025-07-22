<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class SmsAI extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai";
    protected $fillable = ['number','did','message','sms_type','type','date','json_data','sms_id','message_status','operator'];

}

