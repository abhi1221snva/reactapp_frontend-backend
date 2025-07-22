<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessCdrArchive extends Model
{
    public $timestamps = true;
    protected $table = "ringless_cdr_archive";
    protected $fillable = ['id','type','number','cli','channel','duration','unit_minute','charge','start_time','end_time','call_recording','campaign_id','lead_id','dnis','isFree','currency_code','client_package_id','user_id','billable_minutes','billable_charge','area_code','country_code','status'];
}