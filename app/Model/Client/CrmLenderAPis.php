<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class CrmLenderAPis extends Model
{
    public $timestamps = true;

    protected $table = "crm_lender_apis";
    protected $fillable = ["username","password","api_key","bittyadvance_label","url","type","crm_lender_id","sales_rep_email","partner_api_key","auth_url","client_id"];

}
