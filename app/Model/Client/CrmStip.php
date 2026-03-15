<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class CrmStip extends Model
{
    protected $table = 'crm_stips';
    protected $fillable = ['lead_id','lender_id','stip_name','stip_type','status','document_id','requested_by','requested_at','uploaded_at','approved_at','approved_by','notes'];
    protected $casts = ['requested_at'=>'datetime','uploaded_at'=>'datetime','approved_at'=>'datetime'];
    public const STATUSES = ['requested','uploaded','approved','rejected'];
    public const TYPES = ['bank_statement','voided_check','drivers_license','tax_return','lease_agreement','business_license','void_check','articles_of_incorporation','custom'];
}
