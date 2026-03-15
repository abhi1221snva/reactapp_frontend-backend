<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmEmailTemplate extends Model
{
    public $timestamps = true;
    protected $table = "crm_email_templates";
    protected $fillable = ['template_name','template_html','subject','lead_status','send_bcc','status'];

}