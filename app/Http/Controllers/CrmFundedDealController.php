<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\CrmFundedDeal;

class CrmFundedDealController extends Controller
{
    public function show(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $deal = CrmFundedDeal::on($conn)->where('lead_id', (int)$id)->latest()->first();
        return $this->successResponse('Deal retrieved.', $deal ? $deal->toArray() : []);
    }
    public function store(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $this->validate($request, ['funded_amount' => 'required|numeric', 'funding_date' => 'required|date']);
        $conn = "mysql_{$request->auth->parent_id}";
        $data = $request->only(['lender_id','lender_name','funded_amount','factor_rate','term_days','total_payback','daily_payment','funding_date','first_debit_date','contract_number','wire_confirmation','renewal_eligible_at','status']);
        $data['lead_id'] = (int)$id;
        $data['created_by'] = (int)$request->auth->id;
        if (empty($data['status'])) $data['status'] = 'funded';
        $deal = CrmFundedDeal::on($conn)->create($data);
        return $this->successResponse('Deal funded.', ['deal' => $deal], 201);
    }
    public function update(Request $request, $id, $did)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $deal = CrmFundedDeal::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$did);
        $data = $request->only(['lender_id','lender_name','funded_amount','factor_rate','term_days','total_payback','daily_payment','funding_date','first_debit_date','contract_number','wire_confirmation','renewal_eligible_at','status','closed_at']);
        $deal->update(array_filter($data, fn($v) => !is_null($v)));
        return $this->successResponse('Deal updated.', ['deal' => $deal->fresh()]);
    }
    public function markRenewed(Request $request, $id, $did)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $deal = CrmFundedDeal::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$did);
        $deal->update(['status' => 'renewed', 'closed_at' => now()]);
        return $this->successResponse('Deal marked as renewed.', ['deal' => $deal->fresh()]);
    }
}
