<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessCdrLog extends Model
{
    public $timestamps = true;
    protected $table = "rvm_cdr_log";
    protected $fillable = ['id','cli','phone','api_token','api_client_name','sip_trunk_name','sip_trunk_provider','rvm_domain_id','sip_gateway_id','voicemail_drop_log_id','api_type','json_data','timezone_status','status','tries','campaign_id','user_id','voicemail_id'];
}