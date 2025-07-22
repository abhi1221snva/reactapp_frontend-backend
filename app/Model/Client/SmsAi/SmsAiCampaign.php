<?php
namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;
use App\Model\Client\SmsAi\SmsAiLeadTemp;

class SmsAiCampaign extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_campaign";
    protected $fillable = ['id','title','description','status','call_ratio','call_duration','caller_id','custom_caller_id','time_based_calling','call_time_start','call_time_end','country_code','max_lead_temp','min_lead_temp','sms_ai_template_id','last_time_cron_run'];
    public function leadReports()
    {
        return $this->hasMany(SmsAiList::class, 'campaign_id');
    }
    public function SmsAiLeadTemps()
    {
        return $this->hasMany(SmsAiLeadTemp::class, 'campaign_id', 'id');
    }
    public function SmsAiLeadReport()
    {
        return $this->hasMany(SmsAiLeadReport::class, 'campaign_id', 'id');
    }
}

