<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmMerchantPortal extends Model
{
    public $timestamps = true;
    protected $table = "crm_merchant_portals";
    protected $fillable = [
        'lead_id', 'client_id', 'token', 'url', 'status',
        'last_accessed_at', 'access_count', 'notified_at', 'expires_at',
    ];
    protected $dates = ['last_accessed_at', 'notified_at', 'expires_at'];
}
