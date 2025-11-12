<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class Did extends Model
{
    public $timestamps = false;

    protected $table = "did";
    protected $fillable = ['id','cli', 'cnam','area_code','extension','dest_type','operator','ivr_id','conf_id','forward_number','country_code','dest_prefix','voicemail_id','queue_id','ingroup','default_did','voice','fax','sms','sms_phone','sms_email','sms_url','call_time_department_id','call_time_holiday','dest_type_ooh','ivr_id_ooh','extension_ooh','voicemail_id_ooh','forward_number_ooh','country_code_ooh','conf_id_ooh','queue_id_ooh','ingroup_ooh','set_exclusive_for_user','call_screening_ivr_id','language','voice_name','speech_text','ivr_audio_option','prompt_option','call_screening_status','redirect_last_agent','welcome_message','voip_provider','is_deleted','voice_ai','voice_ai_ooh'];
}
