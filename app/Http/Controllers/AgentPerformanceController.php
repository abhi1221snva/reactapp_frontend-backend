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
     * Aggregate stats per agent: total deals, funded amount, commissions.
     */
    public function summary(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conn = $this->tenantDb($request);
        $this->ensureTables($conn);
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        $q = DB::connection($conn)->table('crm_funded_deals as d')
            ->leftJoin('crm_agent_commissions as c', 'c.deal_id', '=', 'd.id');

        if ($dateFrom) $q->where('d.funding_date', '>=', $dateFrom);
        if ($dateTo)   $q->where('d.funding_date', '<=', $dateTo);

        $row = (clone $q)->selectRaw("
            COUNT(d.id)                                       AS total_deals,
            COALESCE(SUM(d.funded_amount), 0)                 AS total_funded_volume,
            COALESCE(SUM(c.agent_commission), 0)              AS total_commissions,
            SUM(CASE WHEN d.status = 'defaulted'  THEN 1 ELSE 0 END) AS default_count
        ")->first();

        $totalDeals        = (int)   ($row->total_deals      ?? 0);
        $totalFundedVolume = (float) ($row->total_funded_volume ?? 0);
        $totalCommissions  = (float) ($row->total_commissions ?? 0);
        $defaultCount      = (int)   ($row->default_count    ?? 0);

        return $this->successResponse('Performance summary retrieved.', [
            'total_funded_volume' => $totalFundedVolume,
            'total_deals'         => $totalDeals,
            'total_commissions'   => $totalCommissions,
            'avg_deal_size'       => $totalDeals > 0 ? round($totalFundedVolume / $totalDeals, 2) : 0,
            'renewal_rate'        => 0,
            'default_rate'        => $totalDeals > 0 ? round($defaultCount / $totalDeals * 100, 1) : 0,
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

        $conn     = $this->tenantDb($request);
        $limit    = (int) $request->input('limit', 20);
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        // Map frontend metric names → SQL aggregate aliases
        $metricMap = [
            'funded_volume' => 'funded_volume',
            'deals'         => 'deals',
            'commission'    => 'commission',
        ];
        $metric = $metricMap[$request->input('metric', 'funded_volume')] ?? 'funded_volume';

        $query = DB::connection($conn)->table('crm_funded_deals as d')
            ->selectRaw("
                d.created_by                               AS agent_id,
                COUNT(d.id)                                AS deals,
                COALESCE(SUM(d.funded_amount), 0)          AS funded_volume,
                COALESCE(SUM(c.agent_commission), 0)       AS commission,
                CASE WHEN COUNT(d.id) > 0
                     THEN ROUND(SUM(d.funded_amount) / COUNT(d.id), 2)
                     ELSE 0 END                            AS avg_deal_size
            ")
            ->leftJoin('crm_agent_commissions as c', 'c.deal_id', '=', 'd.id')
            ->groupBy('d.created_by')
            ->orderByDesc($metric)
            ->limit($limit);

        if ($dateFrom) $query->where('d.funding_date', '>=', $dateFrom);
        if ($dateTo)   $query->where('d.funding_date', '<=', $dateTo);

        $rows = $query->get()->toArray();

        // Resolve agent names from master users table
        $agentIds = array_column($rows, 'agent_id');
        $names = [];
        if (!empty($agentIds)) {
            $users = DB::connection('master')->table('users')
                ->whereIn('id', $agentIds)
                ->select('id', 'name', 'email')
                ->get()
                ->keyBy('id');
            foreach ($agentIds as $id) {
                $u = $users->get($id);
                $names[$id] = $u ? ($u->name ?: $u->email) : "Agent #$id";
            }
        }

        $leaderboard = array_map(function ($row) use ($names) {
            return [
                'agent_id'        => (int)   $row->agent_id,
                'agent_name'      => $names[$row->agent_id] ?? "Agent #{$row->agent_id}",
                'deals'           => (int)   $row->deals,
                'funded_volume'   => (float) $row->funded_volume,
                'commission'      => (float) $row->commission,
                'conversion_rate' => 0,
                'avg_deal_size'   => (float) $row->avg_deal_size,
            ];
        }, $rows);

        return $this->successResponse('Leaderboard retrieved.', $leaderboard);
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

        $conn    = $this->tenantDb($request);
        $agentId = (int) $agentId;

        // Agent name from master
        $user      = DB::connection('master')->table('users')->where('id', $agentId)->first(['name', 'email']);
        $agentName = $user ? ($user->name ?: $user->email) : "Agent #$agentId";

        // Summary stats
        $stats = DB::connection($conn)->table('crm_funded_deals as d')
            ->leftJoin('crm_agent_commissions as c', function ($j) use ($agentId) {
                $j->on('c.deal_id', '=', 'd.id')->where('c.agent_id', $agentId);
            })
            ->where('d.created_by', $agentId)
            ->selectRaw("
                COUNT(d.id)                           AS total_deals,
                COALESCE(SUM(d.funded_amount), 0)     AS funded_volume,
                COALESCE(SUM(c.agent_commission), 0)  AS total_commission,
                CASE WHEN COUNT(d.id) > 0
                     THEN ROUND(SUM(d.funded_amount) / COUNT(d.id), 2)
                     ELSE 0 END                       AS avg_deal_size
            ")
            ->first();

        // Pipeline value: deals not yet defaulted / paid off
        $pipeline = DB::connection($conn)->table('crm_funded_deals')
            ->where('created_by', $agentId)
            ->whereNotIn('status', ['paid_off', 'defaulted'])
            ->sum('funded_amount');

        // Deals list with per-deal commission
        $deals = DB::connection($conn)->table('crm_funded_deals as d')
            ->leftJoin('crm_agent_commissions as c', function ($j) use ($agentId) {
                $j->on('c.deal_id', '=', 'd.id')->where('c.agent_id', $agentId);
            })
            ->leftJoin('crm_leads as l', 'l.id', '=', 'd.lead_id')
            ->where('d.created_by', $agentId)
            ->selectRaw("
                d.id           AS deal_id,
                d.lead_id,
                d.lender_name,
                d.funded_amount,
                d.factor_rate,
                d.status,
                d.funding_date,
                COALESCE(c.agent_commission, 0) AS commission
            ")
            ->orderByDesc('d.funding_date')
            ->limit(100)
            ->get();

        // Attach company_name from EAV (crm_lead_values.field_key = 'company_name')
        $leadIds = $deals->pluck('lead_id')->filter()->unique()->values()->toArray();
        $companyMap = [];
        if (!empty($leadIds)) {
            DB::connection($conn)->table('crm_lead_values')
                ->whereIn('lead_id', $leadIds)
                ->where('field_key', 'company_name')
                ->select('lead_id', 'field_value')
                ->get()
                ->each(function ($r) use (&$companyMap) {
                    $companyMap[$r->lead_id] = $r->field_value;
                });
        }

        $dealList = $deals->map(function ($d) use ($companyMap) {
            return [
                'deal_id'      => (int)    $d->deal_id,
                'lead_id'      => (int)    $d->lead_id,
                'company_name' => $companyMap[$d->lead_id] ?? null,
                'lender_name'  => $d->lender_name,
                'funded_amount'=> (float)  $d->funded_amount,
                'factor_rate'  => $d->factor_rate ? (float) $d->factor_rate : null,
                'commission'   => (float)  $d->commission,
                'status'       => $d->status,
                'funding_date' => $d->funding_date,
            ];
        })->values()->toArray();

        // Monthly trend (last 12 months)
        $monthly = DB::connection($conn)->table('crm_funded_deals')
            ->selectRaw("DATE_FORMAT(funding_date, '%Y-%m') AS month, COUNT(id) AS deals, COALESCE(SUM(funded_amount), 0) AS funded_volume")
            ->where('created_by', $agentId)
            ->where('funding_date', '>=', now()->subMonths(12)->startOfMonth())
            ->groupByRaw("DATE_FORMAT(funding_date, '%Y-%m')")
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month'         => $r->month,
                'deals'         => (int)   $r->deals,
                'funded_volume' => (float) $r->funded_volume,
            ])
            ->values()
            ->toArray();

        return $this->successResponse('Agent detail retrieved.', [
            'agent_id'   => $agentId,
            'agent_name' => $agentName,
            'summary'    => [
                'total_deals'      => (int)   ($stats->total_deals      ?? 0),
                'funded_volume'    => (float) ($stats->funded_volume    ?? 0),
                'total_commission' => (float) ($stats->total_commission ?? 0),
                'avg_deal_size'    => (float) ($stats->avg_deal_size    ?? 0),
                'pipeline_value'   => (float) ($pipeline                ?? 0),
                'conversion_rate'  => 0,
            ],
            'deals'         => $dealList,
            'monthly_trend' => $monthly,
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
            'deal_type'        => 'required|in:new,renewal',
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

        try {
            $conn = $this->tenantDb($request);
            $this->ensureTables($conn);
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
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to load commissions: ' . $e->getMessage(), [], $e);
        }
    }

    /**
     * GET /agent-performance/commissions/summary
     */
    public function commissionSummary(Request $request)
    {
        if ($request->auth->level < 3) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $conn = $this->tenantDb($request);
            $this->ensureTables($conn);

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
}
