<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmAffiliateLink extends Model
{
    public $timestamps = true;
    protected $table = "crm_affiliate_links";
    protected $fillable = [
        'user_id', 'client_id', 'extension_id', 'token', 'full_path',
        'label', 'utm_source', 'utm_medium', 'utm_campaign',
        'redirect_url', 'status', 'total_clicks', 'total_leads',
        'list_id', 'expires_at',
    ];
    protected $dates = ['expires_at'];
}
