<?php

namespace App\Services;

use App\Services\CallPacingService;
use App\Services\AgentAvailabilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PredictiveDialerService
{
    // Industry standard: abandon rate must stay below 3% (FTC/FCC compliance)
    const MAX_ABANDON_RATE   = 0.03;
    const MIN_DIAL_RATIO     = 1.0;
    const MAX_DIAL_RATIO     = 4.0;
    const DEFAULT_DIAL_RATIO = 1.5;
    const PACING_WINDOW      = 300; // 5-minute rolling window for statistics

    /**
     * Calculate the optimal dial ratio for a campaign.
     *
     * Algorithm:
     * - Start at DEFAULT_DIAL_RATIO (1.5 calls per available agent)
     * - Increase ratio when agent idle time is high (agents waiting for calls)
     * - Decrease ratio when abandon rate approaches MAX_ABANDON_RATE
     * - Hard clamp between MIN and MAX
     *
     * @param int $clientId
     * @param int $campaignId
     * @return float
     */
    public static function calculateDialRatio(int $clientId, int $campaignId): float
    {
        $stats = self::getCampaignStats($clientId, $campaignId);

        if ($stats["total_calls"] < 10) {
            // Insufficient data -- use default conservative ratio
            return self::DEFAULT_DIAL_RATIO;
        }

        $currentRatio  = $stats["current_dial_ratio"] ?? self::DEFAULT_DIAL_RATIO;
        $abandonRate   = $stats["abandon_rate"] ?? 0;
        $idleAgents    = $stats["idle_agents"] ?? 0;
        $totalAgents   = $stats["total_agents"] ?? 1;
        $avgHandleTime = $stats["avg_handle_time"] ?? 60; // seconds (used for future AHT-based pacing)

        // Abandon rate too high -- reduce dial ratio aggressively
        if ($abandonRate > self::MAX_ABANDON_RATE) {
            $newRatio = max(self::MIN_DIAL_RATIO, $currentRatio * 0.85);
            Log::info("[PredictiveDial] Reducing ratio -- high abandon rate", [
                "client_id"    => $clientId,
                "campaign_id"  => $campaignId,
                "abandon_rate" => round($abandonRate * 100, 2) . "%",
                "old_ratio"    => $currentRatio,
                "new_ratio"    => $newRatio,
            ]);
            return self::saveDialRatio($clientId, $campaignId, $newRatio);
        }

        // Many idle agents -- increase dial ratio to keep them busy
        $idleRatio = $totalAgents > 0 ? $idleAgents / $totalAgents : 0;
        if ($idleRatio > 0.3) {
            // More than 30% agents idle -- increase calls per agent
            $newRatio = min(self::MAX_DIAL_RATIO, $currentRatio * 1.1);
            return self::saveDialRatio($clientId, $campaignId, $newRatio);
        }

        // Agents are mostly busy -- slight decrease to avoid abandon rate spike
        if ($idleRatio < 0.05) {
            $newRatio = max(self::MIN_DIAL_RATIO, $currentRatio * 0.95);
            return self::saveDialRatio($clientId, $campaignId, $newRatio);
        }

        // Equilibrium -- maintain current ratio
        return $currentRatio;
    }

    /**
     * Get count of available (idle) agents for a campaign.
     */
    public static function getAvailableAgents(int $clientId, int $campaignId): int
    {
        $key = "dialer:available:{$clientId}:{$campaignId}";
        return (int) Cache::get($key, 0);
    }

    /**
     * Register an agent as available/idle for a campaign.
     */
    public static function agentAvailable(int $clientId, int $campaignId, int $agentId): void
    {
        $key    = "dialer:agents:{$clientId}:{$campaignId}";
        $agents = Cache::get($key, []);
        $agents[$agentId] = ["status" => "idle", "since" => time()];
        Cache::put($key, $agents, 3600);

        $countKey = "dialer:available:{$clientId}:{$campaignId}";
        $count    = count(array_filter($agents, fn($a) => $a["status"] === "idle"));
        Cache::put($countKey, $count, 3600);
    }

    /**
     * Mark an agent as busy (on a call).
     */
    public static function agentBusy(int $clientId, int $campaignId, int $agentId): void
    {
        $key    = "dialer:agents:{$clientId}:{$campaignId}";
        $agents = Cache::get($key, []);
        $agents[$agentId] = ["status" => "busy", "since" => time()];
        Cache::put($key, $agents, 3600);

        $countKey = "dialer:available:{$clientId}:{$campaignId}";
        $count    = count(array_filter($agents, fn($a) => $a["status"] === "idle"));
        Cache::put($countKey, $count, 3600);
    }

    /**
     * Record a call outcome for abandon rate tracking.
     *
     * @param int    $clientId
     * @param int    $campaignId
     * @param string $outcome  connected|abandoned|no_answer|busy|failed
     */
    public static function recordCallOutcome(int $clientId, int $campaignId, string $outcome): void
    {
        $key    = "dialer:outcomes:{$clientId}:{$campaignId}";
        $window = time() - self::PACING_WINDOW;

        $outcomes   = Cache::get($key, []);
        $outcomes[] = ["outcome" => $outcome, "time" => time()];

        // Keep only outcomes within the rolling window
        $outcomes = array_values(array_filter($outcomes, fn($o) => $o["time"] > $window));
        Cache::put($key, $outcomes, self::PACING_WINDOW + 60);

        // Recalculate stats
        $total       = count($outcomes);
        $abandoned   = count(array_filter($outcomes, fn($o) => $o["outcome"] === "abandoned"));
        $abandonRate = $total > 0 ? $abandoned / $total : 0;

        $statsKey = "dialer:stats:{$clientId}:{$campaignId}";
        $stats    = Cache::get($statsKey, []);
        $stats["total_calls"]  = $total;
        $stats["abandon_rate"] = $abandonRate;
        Cache::put($statsKey, $stats, self::PACING_WINDOW + 60);
    }

    /**
     * Calculate how many calls to dial right now.
     *
     * calls_to_dial = available_agents * dial_ratio
     */
    public static function callsToDialNow(int $clientId, int $campaignId): int
    {
        $available = self::getAvailableAgents($clientId, $campaignId);
        if ($available === 0) {
            return 0; // No agents available -- don't dial
        }

        $ratio = self::calculateDialRatio($clientId, $campaignId);
        return (int) ceil($available * $ratio);
    }

    /**
     * Get campaign pacing statistics from cache.
     */
    public static function getCampaignStats(int $clientId, int $campaignId): array
    {
        $key = "dialer:stats:{$clientId}:{$campaignId}";
        return Cache::get($key, [
            "total_calls"        => 0,
            "abandon_rate"       => 0,
            "idle_agents"        => 0,
            "total_agents"       => 0,
            "avg_handle_time"    => 60,
            "current_dial_ratio" => self::DEFAULT_DIAL_RATIO,
        ]);
    }

    /**
     * Get a combined pacing + agent breakdown snapshot.
     * Used by the supervisor dashboard.
     */
    public static function getPacingSnapshot(int $clientId, int $campaignId): array
    {
        $pacing       = CallPacingService::for($clientId, $campaignId);
        $availability = AgentAvailabilityService::forClient($clientId);

        return array_merge(
            $pacing->getSnapshot(),
            [
                'legacy_stats'    => self::getCampaignStats($clientId, $campaignId),
                'agent_breakdown' => $availability->getCampaignBreakdown($campaignId),
            ]
        );
    }

    /**
     * Record a call outcome (bridges legacy + new pacing service).
     *
     * @param string $outcome  answered|abandoned|no_answer|busy|failed
     */
    public static function recordOutcome(
        int    $clientId,
        int    $campaignId,
        string $outcome,
        int    $handleTimeSeconds = 0
    ): void {
        // Update legacy rolling window
        $legacyOutcome = $outcome === 'answered' ? 'connected' : $outcome;
        self::recordCallOutcome($clientId, $campaignId, $legacyOutcome);

        // Update Redis-backed pacing counters
        $pacing = CallPacingService::for($clientId, $campaignId);
        $pacing->recordCallPlaced();

        if ($outcome === 'answered') {
            $pacing->recordCallAnswered($handleTimeSeconds);
        } elseif ($outcome === 'abandoned') {
            $pacing->recordCallAbandoned();
        }
    }

    private static function saveDialRatio(int $clientId, int $campaignId, float $ratio): float
    {
        $ratio    = round($ratio, 2);
        $statsKey = "dialer:stats:{$clientId}:{$campaignId}";
        $stats    = Cache::get($statsKey, []);
        $stats["current_dial_ratio"] = $ratio;
        Cache::put($statsKey, $stats, self::PACING_WINDOW + 60);
        return $ratio;
    }
}
