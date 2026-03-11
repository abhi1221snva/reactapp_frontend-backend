<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Agent Availability Service — real-time agent state tracking in Redis.
 *
 * States: available | in_call | wrapping | paused | offline
 *
 * Agents heartbeat every 30s; state expires after 120s without heartbeat.
 */
class AgentAvailabilityService
{
    const STATE_AVAILABLE = 'available';
    const STATE_IN_CALL   = 'in_call';
    const STATE_WRAPPING  = 'wrapping';
    const STATE_PAUSED    = 'paused';
    const STATE_OFFLINE   = 'offline';

    const STATE_TTL = 120; // seconds — agent must heartbeat within this

    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public static function forClient(int $clientId): self
    {
        return new self($clientId);
    }

    // ─── Key helpers ────────────────────────────────────────────────────────────

    private function stateKey(int $agentId): string
    {
        return "agent_state:{$this->clientId}:{$agentId}";
    }

    private function campaignSetKey(int $campaignId): string
    {
        return "campaign_agents:{$this->clientId}:{$campaignId}";
    }

    // ─── State management ────────────────────────────────────────────────────────

    public function setAgentState(int $agentId, string $state, int $campaignId = 0): void
    {
        $key  = $this->stateKey($agentId);
        $data = json_encode([
            'state'       => $state,
            'campaign_id' => $campaignId,
            'updated_at'  => time(),
        ]);
        Redis::set($key, $data, 'EX', self::STATE_TTL);

        // Track in campaign set when agent is active
        if ($campaignId > 0 && in_array($state, [self::STATE_AVAILABLE, self::STATE_IN_CALL, self::STATE_WRAPPING], true)) {
            $cKey = $this->campaignSetKey($campaignId);
            Redis::sadd($cKey, $agentId);
            Redis::expire($cKey, self::STATE_TTL);
        }
    }

    public function getAgentState(int $agentId): array
    {
        $raw = Redis::get($this->stateKey($agentId));
        return $raw
            ? (json_decode($raw, true) ?? ['state' => self::STATE_OFFLINE, 'campaign_id' => 0, 'updated_at' => 0])
            : ['state' => self::STATE_OFFLINE, 'campaign_id' => 0, 'updated_at' => 0];
    }

    // ─── Campaign-level queries ──────────────────────────────────────────────────

    /**
     * Count agents currently in 'available' state for a campaign.
     * Falls back to ExtensionLive DB table if Redis has no data.
     */
    public function getAvailableAgentCount(int $campaignId): int
    {
        $agentIds = Redis::smembers($this->campaignSetKey($campaignId));

        if (empty($agentIds)) {
            return $this->getAvailableAgentsFromDb($campaignId);
        }

        $count = 0;
        foreach ($agentIds as $agentId) {
            if ($this->getAgentState((int) $agentId)['state'] === self::STATE_AVAILABLE) {
                $count++;
            }
        }
        return $count;
    }

    private function getAvailableAgentsFromDb(int $campaignId): int
    {
        try {
            return (int) DB::connection('mysql_' . $this->clientId)
                ->table('extension_live')
                ->where('campaign_id', $campaignId)
                ->where('agent_status', 'available')
                ->where('updated_at', '>', now()->subMinutes(2))
                ->count();
        } catch (\Exception $e) {
            Log::debug("AgentAvailability: DB fallback failed for client {$this->clientId}: {$e->getMessage()}");
            return 0;
        }
    }

    /** Breakdown by state for a campaign. */
    public function getCampaignBreakdown(int $campaignId): array
    {
        $breakdown = ['available' => 0, 'in_call' => 0, 'wrapping' => 0, 'paused' => 0, 'offline' => 0, 'total' => 0];
        $agentIds  = Redis::smembers($this->campaignSetKey($campaignId));

        foreach ($agentIds as $agentId) {
            $state = $this->getAgentState((int) $agentId)['state'] ?? self::STATE_OFFLINE;
            if (isset($breakdown[$state])) {
                $breakdown[$state]++;
            }
            $breakdown['total']++;
        }
        return $breakdown;
    }

    /** Extend agent state TTL (heartbeat). */
    public function heartbeat(int $agentId): void
    {
        $key = $this->stateKey($agentId);
        if (Redis::exists($key)) {
            Redis::expire($key, self::STATE_TTL);
        }
    }

    /** Remove agent from Redis on logout. */
    public function logoutAgent(int $agentId): void
    {
        Redis::del($this->stateKey($agentId));
    }
}
