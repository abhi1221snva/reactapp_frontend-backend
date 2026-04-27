<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use App\Model\Client\CrmCommissionRule;
use App\Model\Client\CrmAgentCommission;
use App\Model\Client\CrmAgentBonus;
use App\Model\Client\CrmFundedDeal;
use App\Services\CommissionCalculationService;

class AgentPerformanceController extends Controller
{
    /**
     * Ensure the three commission / bonus tables exist on the tenant DB.
     */
    private function ensureTables(string $conn): void
    {
        $sb = DB::connection($conn)->getSchemaBuilder();

        if (!$sb->hasTable('crm_commission_rules')) {
            $sb->create('crm_commission_rules', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 200)->default('');
                $table->unsignedBigInteger('lender_id')->nullable();
                $table->string('deal_type', 50)->default('new');
                $table->string('commission_type', 30)->default('percentage');
                $table->decimal('value', 10, 4)->default(0);
                $table->decimal('min_funded_amount', 12, 2)->nullable();
                $table->decimal('max_funded_amount', 12, 2)->nullable();
                $table->decimal('split_agent_pct', 5, 2)->default(50.00);
                $table->string('agent_role', 30)->default('closer');
                $table->unsignedInteger('priority')->default(0);
                $table->tinyInteger('status')->default(1);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['status', 'deal_type']);
                $table->index('lender_id');
            });
        }

        if (!$sb->hasTable('crm_agent_commissions')) {
            $sb->create('crm_agent_commissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('deal_id');
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('agent_id');
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->string('agent_role', 30)->default('closer');
                $table->string('deal_type', 50)->default('new');
                $table->decimal('funded_amount', 12, 2)->default(0);
                $table->string('commission_type', 30)->default('percentage');
                $table->decimal('commission_rate', 10, 4)->default(0);
                $table->decimal('gross_commission', 12, 2)->default(0);
                $table->decimal('agent_commission', 12, 2)->default(0);
                $table->decimal('company_commission', 12, 2)->default(0);
                $table->decimal('override_amount', 12, 2)->default(0);
                $table->unsignedBigInteger('override_from')->nullable();
                $table->enum('status', ['pending', 'approved', 'paid', 'clawback'])->default('pending');
                $table->date('pay_period_start')->nullable();
                $table->date('pay_period_end')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index('deal_id');
                $table->index('lead_id');
                $table->index('agent_id');
                $table->index(['status', 'agent_id']);
                $table->index(['agent_id', 'created_at']);
            });
        }

        if (!$sb->hasTable('crm_agent_bonuses')) {
            $sb->create('crm_agent_bonuses', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('agent_id');
                $table->string('bonus_type', 50);
                $table->string('description', 500)->nullable();
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('period', 20)->nullable();
                $table->enum('status', ['pending', 'approved', 'paid'])->default('pending');
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index('agent_id');
                $table->index(['agent_id', 'period']);
            });
        }
    }

    // ─── Performance ─────────────────────────────────────────────────────────────

    /**
     * GET /agent-performance/summary
     * Aggregate lead pipeline stats.
     */
    public function summary(Request $request)
    {
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn     = $this->tenantDb($request);
        $this->ensureTables($conn);
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        $q = DB::connection($conn)->table('crm_leads')
            ->where('is_deleted', 0);

        if ($request->auth->level < 3) {
            $q->where('assigned_to', (int) $request->auth->id);
        }

        if ($dateFrom) $q->where('created_at', '>=', $dateFrom);
        if ($dateTo)   $q->where('created_at', '<=', $dateTo);

        $row = (clone $q)->selectRaw("
            COUNT(id)                                                AS total_leads,
            SUM(CASE WHEN lead_status = 'new_lead'   THEN 1 ELSE 0 END) AS new_leads,
            SUM(CASE WHEN lead_status = 'submitted'  THEN 1 ELSE 0 END) AS submitted,
            SUM(CASE WHEN lead_status = 'approved'   THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN lead_status = 'funded'     THEN 1 ELSE 0 END) AS funded
        ")->first();

        $total  = (int) ($row->total_leads ?? 0);
        $funded = (int) ($row->funded      ?? 0);

        return $this->successResponse('Performance summary retrieved.', [
            'total_leads'     => $total,
            'new_leads'       => (int) ($row->new_leads  ?? 0),
            'submitted'       => (int) ($row->submitted  ?? 0),
            'approved'        => (int) ($row->approved   ?? 0),
            'funded'          => $funded,
            'conversion_rate' => $total > 0 ? round($funded / $total * 100, 1) : 0,
        ]);
    }

    /**
     * GET /agent-performance/leaderboard
     * Sorted agent ranking by chosen metric.
     */
    public function leaderboard(Request $request)
    {
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn     = $this->tenantDb($request);
        $limit    = (int) $request->input('limit', 50);
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        $metricMap = [
            'total_leads' => 'total_leads',
            'funded'      => 'funded',
            'approved'    => 'approved',
            'submitted'   => 'submitted',
        ];
        $metric = $metricMap[$request->input('metric', 'total_leads')] ?? 'total_leads';

        $query = DB::connection($conn)->table('crm_leads')
            ->selectRaw("
                assigned_to                                              AS agent_id,
                COUNT(id)                                                AS total_leads,
                SUM(CASE WHEN lead_status = 'funded'    THEN 1 ELSE 0 END) AS funded,
                SUM(CASE WHEN lead_status = 'approved'  THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN lead_status = 'submitted' THEN 1 ELSE 0 END) AS submitted
            ")
            ->where('is_deleted', 0)
            ->whereNotNull('assigned_to')
            ->where('assigned_to', '>', 0)
            ->groupBy('assigned_to')
            ->orderByDesc($metric)
            ->limit($limit);

        if ($request->auth->level < 3) {
            $query->where('assigned_to', (int) $request->auth->id);
        }

        if ($dateFrom) $query->where('created_at', '>=', $dateFrom);
        if ($dateTo)   $query->where('created_at', '<=', $dateTo);

        $rows = $query->get()->toArray();

        // Resolve agent names from master users table
        $agentIds = array_column($rows, 'agent_id');
        $names = [];
        if (!empty($agentIds)) {
            DB::connection('master')->table('users')
                ->whereIn('id', $agentIds)
                ->select('id', 'first_name', 'last_name', 'email')
                ->get()
                ->each(function ($u) use (&$names) {
                    $full = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
                    $names[$u->id] = $full ?: $u->email;
                });
        }

        $leaderboard = array_map(function ($row) use ($names) {
            $total  = (int) $row->total_leads;
            $funded = (int) $row->funded;
            return [
                'agent_id'        => (int) $row->agent_id,
                'agent_name'      => $names[$row->agent_id] ?? "Agent #{$row->agent_id}",
                'total_leads'     => $total,
                'funded'          => $funded,
                'approved'        => (int) $row->approved,
                'submitted'       => (int) $row->submitted,
                'conversion_rate' => $total > 0 ? round($funded / $total * 100, 1) : 0,
            ];
        }, $rows);

        return $this->successResponse('Leaderboard retrieved.', $leaderboard);
    }

    /**
     * GET /agent-performance/{agentId}
     * Single agent's lead breakdown, monthly trend, and recent leads.
     */
    public function agentDetail(Request $request, $agentId)
    {
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn    = $this->tenantDb($request);
        $agentId = (int) $agentId;

        // Agents (level < 3) can only view their own detail
        if ($request->auth->level < 3 && $agentId !== (int) $request->auth->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Agent name from master
        $user = DB::connection('master')->table('users')
            ->where('id', $agentId)
            ->first(['first_name', 'last_name', 'email']);
        $agentName = $user
            ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email)
            : "Agent #$agentId";

        // Summary stats from crm_leads
        $stats = DB::connection($conn)->table('crm_leads')
            ->where('assigned_to', $agentId)
            ->where('is_deleted', 0)
            ->selectRaw("
                COUNT(id)                                                    AS total_leads,
                SUM(CASE WHEN lead_status = 'funded'     THEN 1 ELSE 0 END) AS funded,
                SUM(CASE WHEN lead_status = 'approved'   THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN lead_status = 'submitted'  THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN lead_status = 'docs_in'    THEN 1 ELSE 0 END) AS docs_in,
                SUM(CASE WHEN lead_status = 'app_out'    THEN 1 ELSE 0 END) AS app_out
            ")
            ->first();

        $totalLeads = (int) ($stats->total_leads ?? 0);
        $funded     = (int) ($stats->funded      ?? 0);

        // Recent leads (latest 100)
        $leads = DB::connection($conn)->table('crm_leads')
            ->where('assigned_to', $agentId)
            ->where('is_deleted', 0)
            ->select('id', 'lead_status', 'lead_type', 'created_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Attach business_name from EAV
        $leadIds = $leads->pluck('id')->toArray();
        $bizMap  = [];
        if (!empty($leadIds)) {
            DB::connection($conn)->table('crm_lead_values')
                ->whereIn('lead_id', $leadIds)
                ->whereIn('field_key', ['business_name', 'company_name'])
                ->select('lead_id', 'field_value')
                ->get()
                ->each(function ($r) use (&$bizMap) {
                    if (!isset($bizMap[$r->lead_id]) || $r->field_value) {
                        $bizMap[$r->lead_id] = $r->field_value;
                    }
                });
        }

        $leadList = $leads->map(function ($l) use ($bizMap) {
            return [
                'lead_id'       => (int) $l->id,
                'business_name' => $bizMap[$l->id] ?? null,
                'lead_status'   => $l->lead_status,
                'lead_type'     => $l->lead_type,
                'created_at'    => $l->created_at,
            ];
        })->values()->toArray();

        // Monthly trend (last 12 months)
        $monthly = DB::connection($conn)->table('crm_leads')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(id) AS leads")
            ->where('assigned_to', $agentId)
            ->where('is_deleted', 0)
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->month,
                'leads' => (int) $r->leads,
            ])
            ->values()
            ->toArray();

        return $this->successResponse('Agent detail retrieved.', [
            'agent_id'   => $agentId,
            'agent_name' => $agentName,
            'summary'    => [
                'total_leads'     => $totalLeads,
                'funded'          => $funded,
                'approved'        => (int) ($stats->approved  ?? 0),
                'submitted'       => (int) ($stats->submitted ?? 0),
                'docs_in'         => (int) ($stats->docs_in   ?? 0),
                'app_out'         => (int) ($stats->app_out   ?? 0),
                'conversion_rate' => $totalLeads > 0 ? round($funded / $totalLeads * 100, 1) : 0,
            ],
            'leads'         => $leadList,
            'monthly_trend' => $monthly,
        ]);
    }

    // ─── Commission Rules CRUD ───────────────────────────────────────────────────

    /**
     * GET /agent-performance/commission-rules
     */
    public function listRules(Request $request)
    {
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $conn = $this->tenantDb($request);
            $this->ensureTables($conn);
            $rules = CrmCommissionRule::on($conn)->orderByDesc('priority')->get();

            return $this->successResponse('Commission rules retrieved.', ['rules' => $rules->toArray()]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to load commission rules: ' . $e->getMessage(), [], $e);
        }
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
            'deal_type'        => 'required|in:MCA,Term Loan,Line of Credit,SBA,Equipment,Invoice Factoring,Other,new,renewal',
            'commission_type'  => 'required|in:percentage,flat,points',
            'value'            => 'required|numeric|min:0',
            'agent_role'       => 'required|in:closer,opener,both',
            'split_agent_pct'  => 'required|numeric|min:0|max:100',
            'priority'         => 'integer|min:0',
        ]);

        $conn = $this->tenantDb($request);
        $this->ensureTables($conn);
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
            'deal_type'        => 'in:MCA,Term Loan,Line of Credit,SBA,Equipment,Invoice Factoring,Other,new,renewal',
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
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $conn = $this->tenantDb($request);
            $this->ensureTables($conn);
            $perPage = (int) $request->input('per_page', 25);
            $page = (int) $request->input('page', 1);

            $query = CrmAgentCommission::on($conn)->orderByDesc('created_at');

            // Agents (level < 3) only see their own commissions
            if ($request->auth->level < 3) {
                $query->where('agent_id', (int) $request->auth->id);
            } elseif ($request->input('agent_id')) {
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
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to load commissions: ' . $e->getMessage(), [], $e);
        }
    }

    /**
     * GET /agent-performance/commissions/summary
     */
    public function commissionSummary(Request $request)
    {
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $conn = $this->tenantDb($request);
            $this->ensureTables($conn);

            $totalsQuery = DB::connection($conn)->table('crm_agent_commissions');
            $byAgentQuery = DB::connection($conn)->table('crm_agent_commissions');

            // Agents (level < 3) only see their own commissions
            if ($request->auth->level < 3) {
                $totalsQuery->where('agent_id', (int) $request->auth->id);
                $byAgentQuery->where('agent_id', (int) $request->auth->id);
            }

            $totals = $totalsQuery->select(
                    DB::raw('SUM(gross_commission) as total_gross'),
                    DB::raw('SUM(agent_commission) as total_agent'),
                    DB::raw('SUM(company_commission) as total_company'),
                    DB::raw('COUNT(id) as total_records')
                )
                ->first();

            $byAgent = $byAgentQuery->select(
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
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to load commission summary: ' . $e->getMessage(), [], $e);
        }
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
        if ($request->auth->level < 1) {
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
            });

        // Agents (level < 3) only see their own deals
        if ($request->auth->level < 3) {
            $pipeline->where('d.created_by', (int) $request->auth->id);
        }

        $pipeline = $pipeline->orderBy('d.renewal_eligible_at')->get();

        return $this->successResponse('Renewal pipeline retrieved.', ['pipeline' => $pipeline->toArray()]);
    }

    // ─── Bonuses CRUD ────────────────────────────────────────────────────────────

    /**
     * GET /agent-performance/bonuses
     */
    public function listBonuses(Request $request)
    {
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $query = CrmAgentBonus::on($conn)->orderByDesc('created_at');

        // Agents (level < 3) only see their own bonuses
        if ($request->auth->level < 3) {
            $query->where('agent_id', (int) $request->auth->id);
        } elseif ($request->input('agent_id')) {
            $query->where('agent_id', (int) $request->input('agent_id'));
        }
        if ($request->input('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->input('bonus_type')) {
            $query->where('bonus_type', $request->input('bonus_type'));
        }

        $bonuses = $query->get();

        // Attach agent names
        $agentIds = $bonuses->pluck('agent_id')->unique()->filter()->values()->toArray();
        $names = [];
        if (!empty($agentIds)) {
            DB::connection('master')->table('users')
                ->whereIn('id', $agentIds)
                ->select('id', 'name', 'email')
                ->get()
                ->each(function ($u) use (&$names) {
                    $names[$u->id] = $u->name ?: $u->email;
                });
        }

        $list = $bonuses->map(function ($b) use ($names) {
            $arr = is_array($b) ? $b : $b->toArray();
            $arr['agent_name'] = $names[$arr['agent_id']] ?? null;
            return $arr;
        })->values()->toArray();

        return $this->successResponse('Bonuses retrieved.', $list);
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
            'bonus_type' => 'required|in:monthly_target,quarterly_target,spiff,retention,custom,performance,milestone,referral',
            'amount'     => 'required|numeric|min:0',
        ]);

        $conn = $this->tenantDb($request);
        $data = $request->only(['agent_id', 'bonus_type', 'amount', 'description', 'period', 'status', 'notes']);
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
            'bonus_type' => 'in:monthly_target,quarterly_target,spiff,retention,custom,performance,milestone,referral',
            'amount'     => 'numeric|min:0',
            'status'     => 'in:pending,approved,paid,cancelled,clawback',
        ]);

        $conn = $this->tenantDb($request);
        $bonus = CrmAgentBonus::on($conn)->findOrFail((int) $id);
        $data = $request->only(['agent_id', 'bonus_type', 'amount', 'description', 'period', 'status', 'paid_at', 'notes']);
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

    // ─── Agent Dashboard ──────────────────────────────────────────────────────────

    /**
     * GET /crm/agent-dashboard
     * Single endpoint returning all data for the agent's personal dashboard.
     */
    public function agentDashboard(Request $request)
    {
        if ($request->auth->level < 1) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn    = $this->tenantDb($request);
        $agentId = (int) $request->auth->id;

        // ── 1. Summary stats ──────────────────────────────────────────────────
        $stats = DB::connection($conn)->table('crm_leads')
            ->where('assigned_to', $agentId)
            ->where('is_deleted', 0)
            ->selectRaw("
                COUNT(id)                                                    AS total_leads,
                SUM(CASE WHEN DATE(created_at) = CURDATE()          THEN 1 ELSE 0 END) AS new_today,
                SUM(CASE WHEN lead_status = 'submitted'             THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN lead_status = 'approved'              THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN lead_status = 'funded'                THEN 1 ELSE 0 END) AS funded,
                SUM(CASE WHEN lead_status = 'docs_in'               THEN 1 ELSE 0 END) AS docs_in,
                SUM(CASE WHEN lead_status = 'app_out'               THEN 1 ELSE 0 END) AS app_out,
                SUM(CASE WHEN lead_status = 'new_lead'              THEN 1 ELSE 0 END) AS new_leads
            ")
            ->first();

        $total  = (int) ($stats->total_leads ?? 0);
        $funded = (int) ($stats->funded      ?? 0);

        $summary = [
            'total_leads'     => $total,
            'new_today'       => (int) ($stats->new_today  ?? 0),
            'submitted'       => (int) ($stats->submitted  ?? 0),
            'approved'        => (int) ($stats->approved   ?? 0),
            'funded'          => $funded,
            'conversion_rate' => $total > 0 ? round($funded / $total * 100, 1) : 0,
        ];

        // ── 2. Recent leads (last 15) ─────────────────────────────────────────
        $leads = DB::connection($conn)->table('crm_leads')
            ->where('assigned_to', $agentId)
            ->where('is_deleted', 0)
            ->select('id', 'lead_status', 'lead_type', 'created_at')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $leadIds = $leads->pluck('id')->toArray();
        $bizMap  = [];
        if (!empty($leadIds)) {
            DB::connection($conn)->table('crm_lead_values')
                ->whereIn('lead_id', $leadIds)
                ->whereIn('field_key', ['business_name', 'company_name'])
                ->select('lead_id', 'field_value')
                ->get()
                ->each(function ($r) use (&$bizMap) {
                    if (!isset($bizMap[$r->lead_id]) || $r->field_value) {
                        $bizMap[$r->lead_id] = $r->field_value;
                    }
                });
        }

        $recentLeads = $leads->map(function ($l) use ($bizMap) {
            return [
                'id'            => (int) $l->id,
                'business_name' => $bizMap[$l->id] ?? null,
                'lead_status'   => $l->lead_status,
                'lead_type'     => $l->lead_type,
                'created_at'    => $l->created_at,
            ];
        })->values()->toArray();

        // ── 3. Status breakdown ───────────────────────────────────────────────
        $statusBreakdown = DB::connection($conn)->table('crm_leads')
            ->where('assigned_to', $agentId)
            ->where('is_deleted', 0)
            ->selectRaw("lead_status AS status, COUNT(id) AS count")
            ->groupBy('lead_status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->values()
            ->toArray();

        // ── 4. Monthly trend (last 6 months) ─────────────────────────────────
        $monthlyTrend = DB::connection($conn)->table('crm_leads')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(id) AS leads")
            ->where('assigned_to', $agentId)
            ->where('is_deleted', 0)
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'leads' => (int) $r->leads])
            ->values()
            ->toArray();

        // ── 5. Recent activity (last 15 by this agent) ───────────────────────
        $recentActivity = DB::connection($conn)->table('crm_lead_activity')
            ->where('user_id', $agentId)
            ->select('id', 'lead_id', 'activity_type', 'subject', 'created_at')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'id'            => (int) $r->id,
                'lead_id'       => (int) $r->lead_id,
                'activity_type' => $r->activity_type,
                'subject'       => $r->subject,
                'created_at'    => $r->created_at,
            ])
            ->values()
            ->toArray();

        // ── 6. Scheduled tasks ───────────────────────────────────────────────
        $sb = DB::connection($conn)->getSchemaBuilder();
        $tasks = ['today' => [], 'overdue' => [], 'upcoming' => []];

        if ($sb->hasTable('crm_scheduled_task')) {
            $allTasks = DB::connection($conn)->table('crm_scheduled_task')
                ->where('user_id', $agentId)
                ->where('is_sent', 0)
                ->where('date', '<=', now()->addDays(7)->toDateString())
                ->orderBy('date')
                ->orderBy('time')
                ->limit(30)
                ->get();

            $todayStr = now()->toDateString();
            foreach ($allTasks as $t) {
                $item = [
                    'id'        => (int) $t->id,
                    'lead_id'   => (int) $t->lead_id,
                    'task_name' => $t->task_name,
                    'date'      => $t->date,
                    'time'      => $t->time,
                    'notes'     => $t->notes,
                ];
                if ($t->date < $todayStr) {
                    $tasks['overdue'][] = $item;
                } elseif ($t->date === $todayStr) {
                    $tasks['today'][] = $item;
                } else {
                    $tasks['upcoming'][] = $item;
                }
            }
        }

        return $this->successResponse('Agent dashboard data retrieved.', [
            'summary'          => $summary,
            'recent_leads'     => $recentLeads,
            'status_breakdown' => $statusBreakdown,
            'monthly_trend'    => $monthlyTrend,
            'recent_activity'  => $recentActivity,
            'tasks'            => $tasks,
        ]);
    }
}
