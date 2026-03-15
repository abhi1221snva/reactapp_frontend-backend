<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class CrmAutomation extends Model
{
    protected $table = 'crm_automations';
    protected $fillable = ['name','description','trigger_type','trigger_config','conditions','actions','is_active','created_by'];
    protected $casts = ['trigger_config'=>'array','conditions'=>'array','actions'=>'array','is_active'=>'boolean'];
    public const TRIGGER_TYPES = ['status_change','field_update','time_elapsed','document_uploaded','deal_funded','stip_uploaded','offer_received'];
}
