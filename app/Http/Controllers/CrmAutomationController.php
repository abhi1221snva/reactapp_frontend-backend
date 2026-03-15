<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\CrmAutomation;
use App\Model\Client\CrmAutomationLog;

class CrmAutomationController extends Controller
{
    public function index(Request $request)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $automations = CrmAutomation::on($conn)->orderByDesc('created_at')->get();
        return $this->successResponse('Automations retrieved.', ['automations' => $automations]);
    }
    public function store(Request $request)
    {
        $this->validate($request, ['name' => 'required|string', 'trigger_type' => 'required|string', 'actions' => 'required|array']);
        $conn = "mysql_{$request->auth->parent_id}";
        $data = $request->only(['name','description','trigger_type','trigger_config','conditions','actions']);
        $data['created_by'] = (int)$request->auth->id;
        $data['is_active']  = true;
        $automation = CrmAutomation::on($conn)->create($data);
        return $this->successResponse('Automation created.', ['automation' => $automation], 201);
    }
    public function update(Request $request, $id)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $automation = CrmAutomation::on($conn)->findOrFail((int)$id);
        $data = $request->only(['name','description','trigger_type','trigger_config','conditions','actions','is_active']);
        $automation->update(array_filter($data, fn($v) => !is_null($v)));
        return $this->successResponse('Automation updated.', ['automation' => $automation->fresh()]);
    }
    public function destroy(Request $request, $id)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        CrmAutomation::on($conn)->findOrFail((int)$id)->delete();
        return $this->successResponse('Automation deleted.', []);
    }
    public function toggle(Request $request, $id)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $automation = CrmAutomation::on($conn)->findOrFail((int)$id);
        $automation->update(['is_active' => !$automation->is_active]);
        return $this->successResponse('Automation toggled.', ['automation' => $automation->fresh()]);
    }
    public function logs(Request $request, $id)
    {
        $conn = "mysql_{$request->auth->parent_id}";
        $logs = CrmAutomationLog::on($conn)->where('automation_id', (int)$id)->orderByDesc('created_at')->limit(100)->get();
        return $this->successResponse('Logs retrieved.', ['logs' => $logs]);
    }
}
