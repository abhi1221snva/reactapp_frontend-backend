<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\CrmStip;
use Carbon\Carbon;

class CrmStipController extends Controller
{
    public function index(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $q = CrmStip::on($conn)->where('lead_id', (int)$id);
        if ($request->query('lender_id')) $q->where('lender_id', (int)$request->query('lender_id'));
        return $this->successResponse('Stips retrieved.', $q->orderByDesc('created_at')->get()->toArray());
    }
    public function store(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $this->validate($request, ['stip_name' => 'required|string', 'stip_type' => 'required|string']);
        $conn = "mysql_{$request->auth->parent_id}";
        $data = $request->only(['lender_id','stip_name','stip_type','notes']);
        $data['lead_id'] = (int)$id;
        $data['requested_by'] = (int)$request->auth->id;
        $data['requested_at'] = Carbon::now();
        $data['status'] = 'requested';
        $stip = CrmStip::on($conn)->create($data);
        return $this->successResponse('Stip created.', ['stip' => $stip], 201);
    }
    public function bulkCreate(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $this->validate($request, ['stip_names' => 'required|array', 'stip_type' => 'required|string']);
        $conn = "mysql_{$request->auth->parent_id}";
        $created = [];
        foreach ($request->input('stip_names') as $name) {
            $created[] = CrmStip::on($conn)->create([
                'lead_id'      => (int)$id,
                'lender_id'    => $request->input('lender_id'),
                'stip_name'    => $name,
                'stip_type'    => $request->input('stip_type'),
                'status'       => 'requested',
                'requested_by' => (int)$request->auth->id,
                'requested_at' => Carbon::now(),
            ]);
        }
        return $this->successResponse('Stips created.', ['stips' => $created], 201);
    }
    public function update(Request $request, $id, $sid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $stip = CrmStip::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$sid);
        $status = $request->input('status');
        $data = ['notes' => $request->input('notes', $stip->notes)];
        if ($status) {
            $data['status'] = $status;
            if ($status === 'uploaded')  $data['uploaded_at']  = Carbon::now();
            if ($status === 'approved')  { $data['approved_at'] = Carbon::now(); $data['approved_by'] = (int)$request->auth->id; }
        }
        $stip->update($data);
        return $this->successResponse('Stip updated.', ['stip' => $stip->fresh()]);
    }
    public function destroy(Request $request, $id, $sid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
        $conn = "mysql_{$request->auth->parent_id}";
        $stip = CrmStip::on($conn)->where('lead_id', (int)$id)->findOrFail((int)$sid);
        $stip->delete();
        return $this->successResponse('Stip deleted.', []);
    }
}
