<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;
use App\Model\Client\Ringless\RinglessList;

class RinglessCampaign extends Model
{
    public $timestamps = true;
    protected $table = "ringless_campaign";
    protected $fillable = ['id','title','description','status','caller_id','custom_caller_id','time_based_calling','call_time_start','call_time_end','call_ratio','call_duration','last_time_cron_run','max_lead_temp','min_lead_temp','country_code','voice_template_id','sip_gateway_id'];
    public function ringlessList()
    {
        return $this->hasMany(RinglessList::class, 'campaign_id');
    }
    public function ringlessLeadTemps()
    {
        return $this->hasMany(RinglessLeadTemp::class, 'campaign_id', 'id');
    }
    public function ringlessLeadReport()
    {
        return $this->hasMany(RinglessLeadReport::class, 'campaign_id', 'id');
    }
}

