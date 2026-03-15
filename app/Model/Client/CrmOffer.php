<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class CrmOffer extends Model
{
    protected $table = 'crm_offers';
    protected $fillable = ['lead_id','lender_id','lender_name','offered_amount','factor_rate','term_days','daily_payment','total_payback','stips_required','offer_expires_at','status','decline_reason','notes','created_by'];
    protected $casts = ['stips_required' => 'array', 'offer_expires_at' => 'datetime', 'offered_amount' => 'float', 'factor_rate' => 'float', 'daily_payment' => 'float', 'total_payback' => 'float'];
    public const STATUSES = ['pending','received','accepted','declined','expired'];
}
