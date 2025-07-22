<?php
namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;

class SmsAiTemplates extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_templates";
    protected $fillable = ['id','template_name','introduction','description','status','is_deleted'];
}

