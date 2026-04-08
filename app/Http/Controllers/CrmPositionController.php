<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\CrmMerchantPosition;

class CrmPositionController extends Controller
{
    public function index(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $positions = CrmMerchantPosition::on($conn)->where('lead_id', (int)$id)->orderBy('position_number')->get();
        return $this->successResponse('Positions retrieved.', $positions->toArray());
    }
    public function store(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $this->validate($request, ['lender_name' => 'required|string', 'daily_payment' => 'required|numeric']);
        $conn = "mysql_{$request->auth->parent_id}";
        $data = $request->only(['lender_name','funded_amount','factor_rate','daily_payment','start_date','est_payoff_date','remaining_balance','position_number','source','notes']);
        $data['lead_id'] = (int)$id;
        if (empty($data['position_number'])) {
            $data['position_number'] = (CrmMerchantPosition::on($conn)->where('lead_id', (int)$id)->count() + 1);
        }
        $position = CrmMerchantPosition::on($conn)->create($data);
        return $this->successResponse('Position added.', ['position' => $position], 201);
    }
    public function destroy(Request $request, $id, $pid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $position = CrmMerchantPosition::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$pid);
        $position->delete();
        return $this->successResponse('Position deleted.', []);
    }
}
