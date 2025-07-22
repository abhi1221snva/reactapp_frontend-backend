<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class ChatAISetting extends Model
{
    public $timestamps = true;
    protected $table = "chat_ai_setting";
    protected $fillable = ['introduction','description','access_token'];

}

