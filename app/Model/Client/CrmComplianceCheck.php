<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class CrmComplianceCheck extends Model
{
    protected $table = 'crm_compliance_checks';
    protected $fillable = ['lead_id','check_type','result','score','notes','meta','run_by'];
    protected $casts = ['meta' => 'array'];
    public const CHECK_TYPES = ['ofac','kyc','fraud_flag','credit_pull','background','sos_verification','custom'];
    public const RESULTS = ['pass','fail','pending','review'];
}
