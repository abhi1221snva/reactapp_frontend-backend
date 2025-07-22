<?php
namespace App\Model\Client;

use App\Exceptions\RenderableException;
use Illuminate\Database\Eloquent\Model;

class SmsProviders extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $table = "sms_providers";

    protected $fillable = [
        'auth_id',
        'api_key',
        'status',
        'provider',
        'domain_api_url',
        'access_token',
        'sms_ai_url',
        'label_name',
        'sip_username',
        'sip_password',
        'host',
        'user_extension_id'
    ];

    
}
