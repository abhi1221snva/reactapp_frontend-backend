<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class ChatAI extends Model
{
    public $timestamps = true;
    protected $table = "chat_ai_data";
    protected $fillable = ['message','sms_type','type','date','json_data','customer_id'];

}

