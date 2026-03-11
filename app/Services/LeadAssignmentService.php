<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Lead Assignment Service
 *
 * Distributes leads to agents using configurable strategies:
 *   round_robin   — sequential rotation through available agents
 *   least_loaded  — assign to agent with fewest active leads
 *   priority      — assign to highest-level available agent
 */
class LeadAssignmentService
{
    const STRATEGY_ROUND_ROBIN  = 'round_robin';
    const STRATEGY_LEAST_LOADED = 'least_loaded';
    const STRATEGY_PRIORITY     = 'priority';

    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public static function forClient(int $clientId): self
    {
        return new self($clientId);
    }

    private function db()
    {
        return DB::connection('mysql_' . $this->clientId);
    }

    // ─── Agent selection ─────────────────────────────────────────────────────────

    /**
     * Select the best agent for a lead given a campaign and strategy.
     */
    public function selectAgent(int $campaignId, string $strategy = self::STRATEGY_ROUND_ROBIN): ?int
    {
        return match ($strategy) {
            self::STRATEGY_ROUND_ROBIN  => $this->roundRobinSelect($campaignId),
            self::STRATEGY_LEAST_LOADED => $this->leastLoadedSelect($campaignId),
            self::STRATEGY_PRIORITY     => $this->prioritySelect($campaignId),
            default                     => $this->roundRobinSelect($campaignId),
        };
    }

    private function roundRobinSelect(int $campaignId): ?int
    {
        $agents = $this->getCampaignAgentIds($campaignId);
        if (empty($agents)) return null;

        $key = "rr_pos:{$this->clientId}:{$campaignId}";
        $pos = (int) Redis::incr($key) % count($agents);
        Redis::expire($key, 86400);

        return (int) $agents[$pos];
    }

    private function leastLoadedSelect(int $campaignId): ?int
    {
        $agent = $this->db()->table('users AS u')
            ->join('extension_group_map AS gm', 'u.id', '=', 'gm.extension_id')
            ->join('extension_group AS eg', 'gm.group_id', '=', 'eg.id')
            ->leftJoin(
                DB::raw('(SELECT assigned_to, COUNT(*) AS lead_count
                          FROM list_data
                          WHERE assigned_to IS NOT NULL
                            AND status NOT IN ("completed","dnc")
                          GROUP BY assigned_to) AS lc'),
                'u.id',
                '=',
                'lc.assigned_to'
            )
            ->where('eg.campaign_id', $campaignId)
            ->where('u.status', 1)
            ->selectRaw('u.id, COALESCE(lc.lead_count, 0) AS lead_count')
            ->orderBy('lead_count')
            ->first();

        return $agent ? (int) $agent->id : null;
    }

    private function prioritySelect(int $campaignId): ?int
    {
        $agent = $this->db()->table('users AS u')
            ->join('extension_group_map AS gm', 'u.id', '=', 'gm.extension_id')
            ->join('extension_group AS eg', 'gm.group_id', '=', 'eg.id')
            ->where('eg.campaign_id', $campaignId)
            ->where('u.status', 1)
            ->orderByDesc('u.level')
            ->select('u.id')
            ->first();

        return $agent ? (int) $agent->id : null;
    }

    private function getCampaignAgentIds(int $campaignId): array
    {
        return $this->db()->table('users AS u')
            ->join('extension_group_map AS gm', 'u.id', '=', 'gm.extension_id')
            ->join('extension_group AS eg', 'gm.group_id', '=', 'eg.id')
            ->where('eg.campaign_id', $campaignId)
            ->where('u.status', 1)
            ->pluck('u.id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }

    // ─── Bulk assignment ─────────────────────────────────────────────────────────

    /**
     * Assign a list of leads (by ID) to agents round-robin.
     *
     * @return array{assigned: int, unassigned: int}
     */
    public function bulkAssign(array $leadIds, int $campaignId, string $strategy = self::STRATEGY_ROUND_ROBIN): array
    {
        if (empty($leadIds)) {
            return ['assigned' => 0, 'unassigned' => 0];
        }

        $agentIds   = $this->getCampaignAgentIds($campaignId);
        $agentCount = count($agentIds);

        if ($agentCount === 0) {
            Log::warning("LeadAssignment: No agents for campaign {$campaignId}", ['client' => $this->clientId]);
            return ['assigned' => 0, 'unassigned' => count($leadIds)];
        }

        $assigned = 0;
        $total    = count($leadIds);

        foreach ($leadIds as $idx => $leadId) {
            try {
                $agentId = (int) $agentIds[$idx % $agentCount];
                $this->db()->table('list_data')
                    ->where('id', $leadId)
                    ->update(['assigned_to' => $agentId, 'updated_at' => now()]);
                $assigned++;
            } catch (\Exception $e) {
                Log::warning("LeadAssignment: Failed for lead {$leadId} — {$e->getMessage()}");
            }
        }

        Log::info("LeadAssignment: Bulk assigned {$assigned}/{$total}", [
            'client'   => $this->clientId,
            'campaign' => $campaignId,
            'strategy' => $strategy,
        ]);

        return ['assigned' => $assigned, 'unassigned' => $total - $assigned];
    }

    /**
     * Auto-distribute all unassigned leads for a campaign.
     *
     * @return array{assigned: int, unassigned: int, message?: string}
     */
    public function autoDistribute(int $campaignId, int $limit = 1000, string $strategy = self::STRATEGY_ROUND_ROBIN): array
    {
        $leadIds = $this->db()->table('list_data AS ld')
            ->join('campaign_list AS cl', 'ld.list_id', '=', 'cl.list_id')
            ->where('cl.campaign_id', $campaignId)
            ->whereNull('ld.assigned_to')
            ->where('ld.status', 'pending')
            ->limit($limit)
            ->pluck('ld.id')
            ->toArray();

        if (empty($leadIds)) {
            return ['assigned' => 0, 'unassigned' => 0, 'message' => 'No unassigned pending leads found'];
        }

        return $this->bulkAssign($leadIds, $campaignId, $strategy);
    }
}
