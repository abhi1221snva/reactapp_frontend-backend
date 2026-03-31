<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\CrmCommissionRule;
use App\Model\Client\CrmAgentCommission;
use App\Model\Client\CrmAgentBonus;
use App\Model\Client\CrmFundedDeal;
use App\Services\CommissionCalculationService;

class AgentPerformanceController extends Controller
{
    // ─── Performance ─────────────────────────────────────────────────────────────

    /**
     * GET /agent-performance/summary
     * Aggregate stats per agent: total deals, funded amount, commissions.
     */
    public function summary(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = DB::connection($conn)->table('crm_funded_deals as d')
            ->select(
                'd.created_by as agent_id',
                DB::raw('COUNT(d.id) as total_deals'),
                DB::raw('SUM(d.funded_amount) as total_funded'),
                DB::raw('COALESCE(SUM(c.agent_commission), 0) as total_agent_commission'),
                DB::raw('COALESCE(SUM(c.gross_commission), 0) as total_gross_commission')
            )
            ->leftJoin('crm_agent_commissions as c', 'c.deal_id', '=', 'd.id')
            ->groupBy('d.created_by');

        if ($dateFrom) {
            $query->where('d.funding_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('d.funding_date', '<=', $dateTo);
        }

        $agents = $query->get();

        $totals = [
            'total_deals'            => $agents->sum('total_deals'),
            'total_funded'           => $agents->sum('total_funded'),
            'total_agent_commission' => $agents->sum('total_agent_commission'),
            'total_gross_commission' => $agents->sum('total_gross_commission'),
        ];

        return $this->successResponse('Performance summary retrieved.', [
            'totals' => $totals,
            'agents' => $agents->toArray(),
        ]);
    }

    /**
     * GET /agent-performance/leaderboard
     * Sorted agent ranking by chosen metric.
     */
    public function leaderboard(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $metric = $request->input('metric', 'total_funded'); // total_funded | total_deals | total_agent_commission
        $limit = (int) $request->input('limit', 10);
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $allowedMetrics = ['total_funded', 'total_deals', 'total_agent_commission'];
        if (!in_array($metric, $allowedMetrics)) {
            $metric = 'total_funded';
        }

        $query = DB::connection($conn)->table('crm_funded_deals as d')
            ->select(
                'd.created_by as agent_id',
                DB::raw('COUNT(d.id) as total_deals'),
                DB::raw('SUM(d.funded_amount) as total_funded'),
                DB::raw('COALESCE(SUM(c.agent_commission), 0) as total_agent_commission')
            )
            ->leftJoin('crm_agent_commissions as c', 'c.deal_id', '=', 'd.id')
            ->groupBy('d.created_by')
            ->orderByDesc($metric)
            ->limit($limit);

        if ($dateFrom) {
            $query->where('d.funding_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('d.funding_date', '<=', $dateTo);
        }

        $leaderboard = $query->get();

        return $this->successResponse('Leaderboard retrieved.', ['leaderboard' => $leaderboard->toArray()]);
    }

    /**
     * GET /agent-performance/agents/{agentId}
     * Single agent's deals, commissions, and monthly trend.
     */
    public function agentDetail(Request $request, $agentId)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $agentId = (int) $agentId;

        // Agent deals
        $deals = CrmFundedDeal::on($conn)
            ->where('created_by', $agentId)
            ->orderByDesc('funding_date')
            ->limit(100)
            ->get();

        // Agent commissions
        $commissions = CrmAgentCommission::on($conn)
            ->where('agent_id', $agentId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Monthly trend (last 12 months)
        $monthly = DB::connection($conn)->table('crm_funded_deals')
            ->select(
                DB::raw("DATE_FORMAT(funding_date, '%Y-%m') as month"),
                DB::raw('COUNT(id) as deals'),
                DB::raw('SUM(funded_amount) as funded')
            )
            ->where('created_by', $agentId)
            ->where('funding_date', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy(DB::raw("DATE_FORMAT(funding_date, '%Y-%m')"))
            ->orderBy('month')
            ->get();

        return $this->successResponse('Agent detail retrieved.', [
            'agent_id'    => $agentId,
            'deals'       => $deals->toArray(),
            'commissions' => $commissions->toArray(),
            'monthly'     => $monthly->toArray(),
        ]);
    }

    // ─── Commission Rules CRUD ───────────────────────────────────────────────────

    /**
     * GET /agent-performance/commission-rules
     */
    public function listRules(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $rules = CrmCommissionRule::on($conn)->orderByDesc('priority')->get();

        return $this->successResponse('Commission rules retrieved.', ['rules' => $rules->toArray()]);
    }

    /**
     * POST /agent-performance/commission-rules
     */
    public function createRule(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->validate($request, [
            'deal_type'        => 'required|in:new,renewal',
            'commission_type'  => 'required|in:percentage,flat,points',
            'value'            => 'required|numeric|min:0',
            'agent_role'       => 'required|in:closer,opener,both',
            'split_agent_pct'  => 'required|numeric|min:0|max:100',
            'priority'         => 'integer|min:0',
        ]);

        $conn = $this->tenantDb($request);
        $data = $request->only(['lender_id', 'lender_name', 'deal_type', 'commission_type', 'value', 'min_funded_amount', 'max_funded_amount', 'agent_role', 'split_agent_pct', 'priority', 'status', 'notes']);
        if (!isset($data['status'])) {
            $data['status'] = 1;
        }
        if (!isset($data['priority'])) {
            $data['priority'] = 0;
        }

        $rule = CrmCommissionRule::on($conn)->create($data);

        return $this->successResponse('Commission rule created.', ['rule' => $rule->toArray()], 201);
    }

    /**
     * POST /agent-performance/commission-rules/{id}
     */
    public function updateRule(Request $request, $id)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->validate($request, [
            'deal_type'        => 'in:new,renewal',
            'commission_type'  => 'in:percentage,flat,points',
            'value'            => 'numeric|min:0',
            'agent_role'       => 'in:closer,opener,both',
            'split_agent_pct'  => 'numeric|min:0|max:100',
            'priority'         => 'integer|min:0',
        ]);

        $conn = $this->tenantDb($request);
        $rule = CrmCommissionRule::on($conn)->findOrFail((int) $id);
        $data = $request->only(['lender_id', 'lender_name', 'deal_type', 'commission_type', 'value', 'min_funded_amount', 'max_funded_amount', 'agent_role', 'split_agent_pct', 'priority', 'status', 'notes']);
        $rule->update(array_filter($data, fn($v) => !is_null($v)));

        return $this->successResponse('Commission rule updated.', ['rule' => $rule->fresh()]);
    }

    /**
     * DELETE /agent-performance/commission-rules/{id}
     */
    public function deleteRule(Request $request, $id)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $rule = CrmCommissionRule::on($conn)->findOrFail((int) $id);
        $rule->delete();

        return $this->successResponse('Commission rule deleted.');
    }

    // ─── Commission Records ──────────────────────────────────────────────────────

    /**
     * GET /agent-performance/commissions
     */
    public function listCommissions(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $perPage = (int) $request->input('per_page', 25);
        $page = (int) $request->input('page', 1);

        $query = CrmAgentCommission::on($conn)->orderByDesc('created_at');

        if ($request->input('agent_id')) {
            $query->where('agent_id', (int) $request->input('agent_id'));
        }
        if ($request->input('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->input('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->input('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $total = $query->count();
        $commissions = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->successResponse('Commissions retrieved.', [
            'commissions' => $commissions->toArray(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
        ]);
    }

    /**
     * GET /agent-performance/commissions/summary
     */
    public function commissionSummary(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);

        $totals = DB::connection($conn)->table('crm_agent_commissions')
            ->select(
                DB::raw('SUM(gross_commission) as total_gross'),
                DB::raw('SUM(agent_commission) as total_agent'),
                DB::raw('SUM(company_commission) as total_company'),
                DB::raw('COUNT(id) as total_records')
            )
            ->first();

        $byAgent = DB::connection($conn)->table('crm_agent_commissions')
            ->select(
                'agent_id',
                DB::raw('SUM(gross_commission) as total_gross'),
                DB::raw('SUM(agent_commission) as total_agent'),
                DB::raw('SUM(company_commission) as total_company'),
                DB::raw('COUNT(id) as total_records')
            )
            ->groupBy('agent_id')
            ->orderByDesc(DB::raw('SUM(agent_commission)'))
            ->get();

        return $this->successResponse('Commission summary retrieved.', [
            'totals'   => (array) $totals,
            'by_agent' => $byAgent->toArray(),
        ]);
    }

    /**
     * POST /agent-performance/commissions/{id}/approve
     */
    public function approveCommission(Request $request, $id)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $commission = CrmAgentCommission::on($conn)->findOrFail((int) $id);
        $commission->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => (int) $request->auth->id,
        ]);

        return $this->successResponse('Commission approved.', ['commission' => $commission->fresh()]);
    }

    /**
     * POST /agent-performance/commissions/{id}/pay
     */
    public function markPaid(Request $request, $id)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $commission = CrmAgentCommission::on($conn)->findOrFail((int) $id);
        $commission->update([
            'status'  => 'paid',
            'paid_at' => now(),
            'paid_by' => (int) $request->auth->id,
        ]);

        return $this->successResponse('Commission marked as paid.', ['commission' => $commission->fresh()]);
    }

    /**
     * POST /agent-performance/commissions/{id}/clawback
     */
    public function clawback(Request $request, $id)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $commission = CrmAgentCommission::on($conn)->findOrFail((int) $id);
        $commission->update([
            'status'          => 'clawback',
            'clawback_reason' => $request->input('reason', ''),
        ]);

        return $this->successResponse('Commission clawed back.', ['commission' => $commission->fresh()]);
    }

    /**
     * POST /agent-performance/commissions/bulk-approve
     */
    public function bulkApprove(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->validate($request, ['ids' => 'required|array', 'ids.*' => 'integer']);

        $conn = $this->tenantDb($request);
        $ids = $request->input('ids');

        CrmAgentCommission::on($conn)
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->update([
                'status'      => 'approved',
                'approved_at' => now(),
                'approved_by' => (int) $request->auth->id,
            ]);

        return $this->successResponse('Commissions bulk-approved.', ['count' => count($ids)]);
    }

    /**
     * POST /agent-performance/commissions/bulk-pay
     */
    public function bulkPay(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->validate($request, ['ids' => 'required|array', 'ids.*' => 'integer']);

        $conn = $this->tenantDb($request);
        $ids = $request->input('ids');

        CrmAgentCommission::on($conn)
            ->whereIn('id', $ids)
            ->where('status', 'approved')
            ->update([
                'status'  => 'paid',
                'paid_at' => now(),
                'paid_by' => (int) $request->auth->id,
            ]);

        return $this->successResponse('Commissions bulk-paid.', ['count' => count($ids)]);
    }

    /**
     * POST /agent-performance/deals/{dealId}/calculate-commission
     * Trigger commission calculation for a specific deal.
     */
    public function calculateCommission(Request $request, $dealId)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $closerId = $request->input('closer_id') ? (int) $request->input('closer_id') : null;
        $openerId = $request->input('opener_id') ? (int) $request->input('opener_id') : null;

        $commissions = CommissionCalculationService::calculateForDeal($conn, (int) $dealId, $closerId, $openerId);

        return $this->successResponse('Commission calculated.', [
            'commissions' => array_map(fn($c) => $c->toArray(), $commissions),
        ]);
    }

    // ─── Renewals ────────────────────────────────────────────────────────────────

    /**
     * GET /crm/renewals
     * Funded deals approaching renewal eligibility.
     */
    public function renewalPipeline(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        // Frontend sends 'days', fall back to 'days_ahead' for compat
        $daysAhead = (int) ($request->input('days', $request->input('days_ahead', 60)));

        $pipeline = DB::connection($conn)->table('crm_funded_deals as d')
            ->select(
                'd.*',
                'l.lead_status',
                'd.renewal_eligible_at as renewal_eligible_date',
                DB::raw("DATEDIFF(d.renewal_eligible_at, NOW()) as days_until_renewal"),
                DB::raw("(SELECT field_value FROM crm_lead_values WHERE lead_id = d.lead_id AND field_key = 'business_name' LIMIT 1) as company_name"),
                DB::raw("(SELECT field_value FROM crm_lead_values WHERE lead_id = d.lead_id AND field_key = 'first_name' LIMIT 1) as first_name"),
                DB::raw("(SELECT field_value FROM crm_lead_values WHERE lead_id = d.lead_id AND field_key = 'last_name' LIMIT 1) as last_name")
            )
            ->leftJoin('crm_leads as l', 'l.id', '=', 'd.lead_id')
            ->whereIn('d.status', ['funded', 'in_repayment'])
            ->where(function ($q) use ($daysAhead) {
                $q->whereNotNull('d.renewal_eligible_at')
                  ->where('d.renewal_eligible_at', '<=', now()->addDays($daysAhead));
            })
            ->orderBy('d.renewal_eligible_at')
            ->get();

        return $this->successResponse('Renewal pipeline retrieved.', ['pipeline' => $pipeline->toArray()]);
    }

    // ─── Bonuses CRUD ────────────────────────────────────────────────────────────

    /**
     * GET /agent-performance/bonuses
     */
    public function listBonuses(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $query = CrmAgentBonus::on($conn)->orderByDesc('created_at');

        if ($request->input('agent_id')) {
            $query->where('agent_id', (int) $request->input('agent_id'));
        }
        if ($request->input('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->input('bonus_type')) {
            $query->where('bonus_type', $request->input('bonus_type'));
        }

        $bonuses = $query->get();

        return $this->successResponse('Bonuses retrieved.', ['bonuses' => $bonuses->toArray()]);
    }

    /**
     * POST /agent-performance/bonuses
     */
    public function createBonus(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->validate($request, [
            'agent_id'   => 'required|integer',
            'bonus_type' => 'required|in:performance,milestone,referral,retention,custom',
            'amount'     => 'required|numeric|min:0',
        ]);

        $conn = $this->tenantDb($request);
        $data = $request->only(['agent_id', 'bonus_type', 'amount', 'description', 'period_start', 'period_end', 'status', 'notes']);
        if (!isset($data['status'])) {
            $data['status'] = 'pending';
        }

        $bonus = CrmAgentBonus::on($conn)->create($data);

        return $this->successResponse('Bonus created.', ['bonus' => $bonus->toArray()], 201);
    }

    /**
     * POST /agent-performance/bonuses/{id}
     */
    public function updateBonus(Request $request, $id)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->validate($request, [
            'bonus_type' => 'in:performance,milestone,referral,retention,custom',
            'amount'     => 'numeric|min:0',
            'status'     => 'in:pending,approved,paid,cancelled',
        ]);

        $conn = $this->tenantDb($request);
        $bonus = CrmAgentBonus::on($conn)->findOrFail((int) $id);
        $data = $request->only(['agent_id', 'bonus_type', 'amount', 'description', 'period_start', 'period_end', 'status', 'approved_by', 'approved_at', 'paid_at', 'paid_by', 'notes']);
        $bonus->update(array_filter($data, fn($v) => !is_null($v)));

        return $this->successResponse('Bonus updated.', ['bonus' => $bonus->fresh()]);
    }

    /**
     * DELETE /agent-performance/bonuses/{id}
     */
    public function deleteBonus(Request $request, $id)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $bonus = CrmAgentBonus::on($conn)->findOrFail((int) $id);
        $bonus->delete();

        return $this->successResponse('Bonus deleted.');
    }
}
