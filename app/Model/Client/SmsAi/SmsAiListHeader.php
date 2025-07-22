<?php
namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;

class SmsAiListHeader extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_list_header";
    protected $fillable = ['id','list_id','header','column_name','label_id','is_dialling','is_deleted'];
}