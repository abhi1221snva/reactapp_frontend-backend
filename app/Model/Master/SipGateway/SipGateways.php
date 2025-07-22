<?php

namespace App\Model\Master\SipGateway;

use Illuminate\Database\Eloquent\Model;

class SipGateways extends Model
{
    protected $connection = 'master';
    protected $table = 'sip_gateways';
    public $timestamps = true;

    protected $fillable = ['id', 'client_name', 'sip_trunk_provider', 'sip_trunk_name', 'sip_trunk_host','sip_trunk_password','sip_trunk_context','sip_twilio_sid','sip_twilio_token','sip_plivo_auth_token','sip_trunk_username','asterisk_server_id'];

}
