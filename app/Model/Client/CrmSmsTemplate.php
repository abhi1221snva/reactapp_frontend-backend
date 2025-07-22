<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class CrmSmsTemplate extends Model
{
    public $timestamps = true;
    protected $table = "crm_sms_templates";
    protected $fillable = ['template_name','template_html','lead_status','status'];

}