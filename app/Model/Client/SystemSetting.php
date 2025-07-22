<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class SystemSetting extends Model
{
    public $timestamps = true;
    protected $table = "crm_system_setting";
    protected $fillable = ['company_name','company_address','company_phone','company_email','state','city','zipcode','logo','company_domain'];
    
}