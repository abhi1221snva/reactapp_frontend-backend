<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmSendLeadToLender extends Model
{
    public $timestamps = true;
    protected $table = "crm_send_lead_to_lender_record";
    protected $fillable = ['lender_id','lead_id','submitted_date','lender_status_id','notes','user_id'];
    public function lender()
    {
        return $this->belongsTo(Lender::class, 'lender_id');
    }

}