<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class OpenAISetting extends Model
{
    public $timestamps = true;
    protected $table = "open_ai_chat_setting";
    protected $fillable = ['cli','introduction','description','access_token','sms_ai_api_url','webhook_url'];

}

