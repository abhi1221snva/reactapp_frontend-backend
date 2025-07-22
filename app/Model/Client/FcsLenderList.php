<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class FcsLenderList extends Model
{
    public $timestamps = true;
    protected $table = "fcs_lender_list";
    protected $fillable = ['lead_id','bank_id','lender_name','funding_date','net','funding','funding_factor','weekly','daily','balance','days','withhold','end_date','transfer_accounts','notes'];
    
}