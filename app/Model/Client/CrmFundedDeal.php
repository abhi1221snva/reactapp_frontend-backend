<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class CrmFundedDeal extends Model
{
    protected $table = 'crm_funded_deals';
    protected $fillable = ['lead_id','lender_id','lender_name','funded_amount','factor_rate','term_days','total_payback','daily_payment','funding_date','first_debit_date','contract_number','wire_confirmation','renewal_eligible_at','status','closed_at','created_by'];
    protected $casts = ['funded_amount' => 'float', 'factor_rate' => 'float', 'total_payback' => 'float', 'daily_payment' => 'float', 'funding_date' => 'date', 'first_debit_date' => 'date'];
    public const STATUSES = ['funded','in_repayment','paid_off','defaulted','renewed'];
}
