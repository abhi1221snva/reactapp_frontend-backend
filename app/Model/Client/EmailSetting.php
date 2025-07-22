<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class EmailSetting extends Model
{
    public $timestamps = true;
    protected $table = "crm_smtp_setting";
    protected $fillable = ['mail_driver','mail_username','mail_password','send_email_via','sender_mail','sender_name','mail_port','mail_encryption','mail_type'];
    
}