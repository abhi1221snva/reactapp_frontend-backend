<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\CrmComplianceCheck;
use Illuminate\Support\Facades\DB;

class CrmComplianceController extends Controller
{
    public function index(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $checks = CrmComplianceCheck::on($conn)->where('lead_id', (int)$id)->orderByDesc('created_at')->get();
        return $this->successResponse('Checks retrieved.', $checks->toArray());
    }
    public function store(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $this->validate($request, ['check_type' => 'required|string']);
        $conn = "mysql_{$request->auth->parent_id}";
        $data = $request->only(['check_type','result','score','notes','meta']);
        $data['lead_id'] = (int)$id;
        $data['run_by']  = (int)$request->auth->id;
        if (empty($data['result'])) $data['result'] = 'pending';
        $check = CrmComplianceCheck::on($conn)->create($data);
        return $this->successResponse('Check created.', ['check' => $check], 201);
    }
    public function update(Request $request, $id, $cid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $check = CrmComplianceCheck::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$cid);
        $data = $request->only(['result','score','notes','meta']);
        $check->update(array_filter($data, fn($v) => !is_null($v)));
        return $this->successResponse('Check updated.', ['check' => $check->fresh()]);
    }
    public function searchAdvanceRegistry(Request $request)
    {
        // Returns empty stub — real implementation would call an external API
        return $this->successResponse('Registry results.', []);
    }
    public function stackingWarning(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $count = DB::connection($conn)->table('crm_funded_deals')->where('lead_id', (int)$id)->whereIn('status',['funded','in_repayment'])->count();
        return $this->successResponse('Stacking warning.', [
            'has_stacking' => $count > 1,
            'active_count' => $count,
            'positions'    => [],
        ]);
    }
}
