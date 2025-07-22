<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class Lender extends Model
{
    public $timestamps = true;
    protected $guarded = ['id'];
    
    protected $table = "crm_lender";
    protected $fillable = ['lender_name','email','secondary_email','secondary_email2','contact_person','phone','status','address','state','city','industry','guideline_state','guideline_file','notes','min_avg_revenue','min_monthly_deposit','max_mca_payoff_amount','loc','ownership_percentage','factor_rate','prohibited_industry','restricted_industry_note','restricted_state_note','secondary_email3','lender_api_type','secondary_email4'];
    public function crmSendLeadToLender()
    {
        return $this->hasMany(CrmSendLeadToLender::class, 'lender_id');
    }
}
