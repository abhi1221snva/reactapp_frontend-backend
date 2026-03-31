<?php

namespace App\Services;

use App\Model\Client\CrmCommissionRule;
use App\Model\Client\CrmAgentCommission;
use App\Model\Client\CrmFundedDeal;
use Illuminate\Support\Facades\DB;

class CommissionCalculationService
{
    /**
     * Calculate commissions for a funded deal and persist them.
     *
     * @param  string  $conn       The tenant DB connection name (e.g. "mysql_42")
     * @param  int     $dealId     The crm_funded_deals.id
     * @param  int|null $closerId  The closer agent's user ID
     * @param  int|null $openerId  The opener agent's user ID
     * @return array  Array of created CrmAgentCommission records
     */
    public static function calculateForDeal(string $conn, int $dealId, ?int $closerId = null, ?int $openerId = null): array
    {
        $deal = CrmFundedDeal::on($conn)->findOrFail($dealId);
        $lenderId = $deal->lender_id;
        $fundedAmount = (float) $deal->funded_amount;
        $dealType = $deal->status === 'renewed' ? 'renewal' : 'new';

        $commissions = [];

        // Find matching rule
        $rule = self::findMatchingRule($conn, $lenderId, $dealType, $fundedAmount);
        if (!$rule) {
            return $commissions;
        }

        $gross = self::calcGross($rule, $fundedAmount);
        $agentPct = (float) $rule->split_agent_pct / 100;
        $agentShare = round($gross * $agentPct, 2);
        $companyShare = round($gross - $agentShare, 2);

        // Determine which agents get commissions based on rule's agent_role
        $agents = [];
        if ($rule->agent_role === 'closer' && $closerId) {
            $agents[] = ['id' => $closerId, 'role' => 'closer'];
        } elseif ($rule->agent_role === 'opener' && $openerId) {
            $agents[] = ['id' => $openerId, 'role' => 'opener'];
        } elseif ($rule->agent_role === 'both') {
            if ($closerId) {
                $agents[] = ['id' => $closerId, 'role' => 'closer'];
            }
            if ($openerId && $openerId !== $closerId) {
                $agents[] = ['id' => $openerId, 'role' => 'opener'];
            }
        }

        // If no agents matched the rule, default to the deal creator as closer
        if (empty($agents) && $deal->created_by) {
            $agents[] = ['id' => (int) $deal->created_by, 'role' => 'closer'];
        }

        $splitCount = count($agents) > 1 ? 2 : 1;

        foreach ($agents as $a) {
            $comm = CrmAgentCommission::on($conn)->create([
                'deal_id'             => $deal->id,
                'lead_id'             => $deal->lead_id,
                'agent_id'            => $a['id'],
                'rule_id'             => $rule->id,
                'agent_role'          => $a['role'],
                'deal_type'           => $dealType,
                'funded_amount'       => $fundedAmount,
                'commission_type'     => $rule->commission_type,
                'commission_rate'     => (float) $rule->value,
                'gross_commission'    => round($gross / $splitCount, 2),
                'agent_commission'    => round($agentShare / $splitCount, 2),
                'company_commission'  => round($companyShare / $splitCount, 2),
                'status'              => 'pending',
            ]);
            $commissions[] = $comm;
        }

        return $commissions;
    }

    /**
     * Find the best matching commission rule for the given parameters.
     * Rules with a specific lender_id take precedence over null (wildcard) rules.
     * Higher priority value wins ties.
     */
    public static function findMatchingRule(string $conn, ?int $lenderId, string $dealType, float $fundedAmount): ?CrmCommissionRule
    {
        return CrmCommissionRule::on($conn)
            ->where('status', 1)
            ->where('deal_type', $dealType)
            ->where(function ($q) use ($lenderId) {
                $q->whereNull('lender_id')->orWhere('lender_id', $lenderId);
            })
            ->where(function ($q) use ($fundedAmount) {
                $q->where(function ($q2) use ($fundedAmount) {
                    $q2->whereNull('min_funded_amount')->orWhere('min_funded_amount', '<=', $fundedAmount);
                })->where(function ($q2) use ($fundedAmount) {
                    $q2->whereNull('max_funded_amount')->orWhere('max_funded_amount', '>=', $fundedAmount);
                });
            })
            ->orderByDesc('priority')
            ->orderByRaw('lender_id IS NOT NULL DESC')
            ->first();
    }

    /**
     * Calculate gross commission amount from the rule and funded amount.
     * Flat = fixed dollar amount, percentage/points = % of funded amount.
     */
    private static function calcGross(CrmCommissionRule $rule, float $fundedAmount): float
    {
        $val = (float) $rule->value;
        if ($rule->commission_type === 'flat') {
            return $val;
        }
        // Both 'percentage' and 'points' are treated as a % of funded amount
        return round($fundedAmount * ($val / 100), 2);
    }
}
