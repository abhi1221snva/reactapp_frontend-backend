<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmScheduledTask extends Model
{
    public $timestamps = true;
    protected $table = "crm_scheduled_task";
    protected $fillable = ['lead_id','task_name','date','time','notes','user_id','is_sent'];

}