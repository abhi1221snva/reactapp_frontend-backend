<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class CrmLenderApiLabels extends Model
{
    public $timestamps = true;
    protected $table = "crm_lender_apis_label_setting";
    protected $fillable = ["crm_label_id","ondeck_label","credibly_label","bittyadvance_label","fox_partner","specialty_label","forward_financing_label","cancapital_label","rapid_label","biz2credit"];
    
}