<?php
namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;

class SmsAiList extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_list";
    protected $fillable = ['id','title','campaign_id','total_leads','file_name','status'];
    public function smsAiListData()
    {
        return $this->hasMany(SmsAiListData::class, 'list_id');
    }
    public function smsAiLeadReport()
    {
        return $this->hasMany(SmsAiLeadReport::class, 'list_id');
    }
}

