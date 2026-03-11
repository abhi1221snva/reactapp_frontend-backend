<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Call Pacing Service — Redis-backed real-time pacing metrics.
 *
 * Tracks call outcomes in a rolling 5-minute window to calculate
 * an optimal dial ratio that keeps abandon rate < 3% (FTC/FCC).
 *
 * Redis keys (per client + campaign):
 *   pacing:{clientId}:{campaignId}:calls_placed   — total dials
 *   pacing:{clientId}:{campaignId}:calls_answered  — human-answered calls
 *   pacing:{clientId}:{campaignId}:calls_abandoned — abandoned (no agent)
 *   pacing:{clientId}:{campaignId}:handle_times    — list of handle times (s)
 *   pacing:{clientId}:{campaignId}:agents_available — available agent count
 *   pacing:{clientId}:{campaignId}:current_ratio   — last calculated ratio
 */
class CallPacingService
{
    const WINDOW_SECONDS  = 300;   // 5-minute rolling window
    const MAX_ABANDON_RATE = 0.03; // FTC 3% limit
    const MIN_RATIO        = 1.0;
    const MAX_RATIO        = 4.0;
    const MIN_SAMPLE_SIZE  = 10;   // minimum calls before pacing activates

    private int $clientId;
    private int $campaignId;

    public function __construct(int $clientId, int $campaignId)
    {
        $this->clientId   = $clientId;
        $this->campaignId = $campaignId;
    }

    public static function for(int $clientId, int $campaignId): self
    {
        return new self($clientId, $campaignId);
    }

    // ─── Key helpers ────────────────────────────────────────────────────────────

    private function key(string $metric): string
    {
        return "pacing:{$this->clientId}:{$this->campaignId}:{$metric}";
    }

    // ─── Recording outcomes ──────────────────────────────────────────────────────

    public function recordCallPlaced(): void
    {
        $key = $this->key('calls_placed');
        Redis::incr($key);
        Redis::expire($key, self::WINDOW_SECONDS * 2);
    }

    public function recordCallAnswered(int $handleTimeSeconds = 0): void
    {
        $k = $this->key('calls_answered');
        Redis::incr($k);
        Redis::expire($k, self::WINDOW_SECONDS * 2);

        if ($handleTimeSeconds > 0) {
            $htKey = $this->key('handle_times');
            Redis::rpush($htKey, $handleTimeSeconds);
            Redis::ltrim($htKey, -100, -1); // keep last 100 values
            Redis::expire($htKey, self::WINDOW_SECONDS * 2);
        }
    }

    public function recordCallAbandoned(): void
    {
        $k = $this->key('calls_abandoned');
        Redis::incr($k);
        Redis::expire($k, self::WINDOW_SECONDS * 2);
    }

    public function updateAvailableAgents(int $count): void
    {
        Redis::set($this->key('agents_available'), $count, 'EX', 60);
    }

    // ─── Reading metrics ─────────────────────────────────────────────────────────

    public function getCallsPlaced(): int
    {
        return (int) (Redis::get($this->key('calls_placed')) ?? 0);
    }

    public function getCallsAnswered(): int
    {
        return (int) (Redis::get($this->key('calls_answered')) ?? 0);
    }

    public function getCallsAbandoned(): int
    {
        return (int) (Redis::get($this->key('calls_abandoned')) ?? 0);
    }

    public function getAvailableAgents(): int
    {
        return (int) (Redis::get($this->key('agents_available')) ?? 0);
    }

    public function getAbandonRate(): float
    {
        $answered = $this->getCallsAnswered();
        if ($answered === 0) return 0.0;
        return min(1.0, $this->getCallsAbandoned() / $answered);
    }

    public function getAverageHandleTime(): float
    {
        $times = Redis::lrange($this->key('handle_times'), 0, -1);
        if (empty($times)) return 30.0;
        return array_sum($times) / count($times);
    }

    // ─── Core pacing algorithm ───────────────────────────────────────────────────

    /**
     * Calculate optimal dial ratio.
     *
     * 1. Warm-up phase (< MIN_SAMPLE_SIZE calls): conservative 1.2
     * 2. Abandon rate > 3%: reduce ratio (FTC compliance)
     * 3. Abandon rate < 1.5%: cautiously increase ratio
     * 4. Otherwise: hold current ratio
     * 5. Clamp to [MIN_RATIO, MAX_RATIO]
     */
    public function calculateOptimalDialRatio(): float
    {
        $callsPlaced   = $this->getCallsPlaced();
        $availAgents   = $this->getAvailableAgents();
        $abandonRate   = $this->getAbandonRate();
        $avgHandleTime = $this->getAverageHandleTime();

        // Not enough data or no agents
        if ($callsPlaced < self::MIN_SAMPLE_SIZE || $availAgents === 0) {
            return 1.2;
        }

        $currentRatio = (float) (Redis::get($this->key('current_ratio')) ?? 1.5);

        if ($abandonRate > self::MAX_ABANDON_RATE) {
            // Aggressively reduce — overshoot correction proportional to excess
            $reduction = 1.0 + (($abandonRate - self::MAX_ABANDON_RATE) / self::MAX_ABANDON_RATE) * 0.5;
            $newRatio  = $currentRatio / $reduction;
            Log::info("[CallPacing] Abandon {$abandonRate} > 3% — reducing ratio", [
                'client'   => $this->clientId,
                'campaign' => $this->campaignId,
                'from'     => $currentRatio,
                'to'       => $newRatio,
            ]);
        } elseif ($abandonRate < self::MAX_ABANDON_RATE / 2) {
            // Safely increase — Erlang-inspired target
            $erlangTarget = $availAgents > 0
                ? min(($availAgents * $avgHandleTime) / max($avgHandleTime, 20), self::MAX_RATIO)
                : $currentRatio;
            $newRatio = min($erlangTarget, $currentRatio + 0.15); // max +0.15 per cycle
        } else {
            $newRatio = $currentRatio; // hold
        }

        $newRatio = round(max(self::MIN_RATIO, min(self::MAX_RATIO, $newRatio)), 2);
        Redis::set($this->key('current_ratio'), $newRatio, 'EX', self::WINDOW_SECONDS);

        return $newRatio;
    }

    /**
     * How many calls to dial right now.
     * max(0, floor(agents * ratio) - callsInProgress), capped at 3x agents.
     */
    public function getCallsToDialNow(int $callsInProgress = 0): int
    {
        $availAgents = $this->getAvailableAgents();
        if ($availAgents === 0) return 0;

        $ratio  = $this->calculateOptimalDialRatio();
        $target = (int) floor($availAgents * $ratio);
        $toDial = max(0, $target - $callsInProgress);

        return min($toDial, $availAgents * 3); // safety cap
    }

    /** Reset pacing counters (e.g. campaign pause/resume) */
    public function reset(): void
    {
        foreach (['calls_placed', 'calls_answered', 'calls_abandoned', 'handle_times', 'current_ratio'] as $m) {
            Redis::del($this->key($m));
        }
    }

    /** Full pacing snapshot for monitoring dashboard */
    public function getSnapshot(): array
    {
        return [
            'client_id'         => $this->clientId,
            'campaign_id'       => $this->campaignId,
            'calls_placed'      => $this->getCallsPlaced(),
            'calls_answered'    => $this->getCallsAnswered(),
            'calls_abandoned'   => $this->getCallsAbandoned(),
            'abandon_rate_pct'  => round($this->getAbandonRate() * 100, 2),
            'avg_handle_time_s' => round($this->getAverageHandleTime(), 1),
            'available_agents'  => $this->getAvailableAgents(),
            'current_ratio'     => $this->calculateOptimalDialRatio(),
            'ftc_compliant'     => $this->getAbandonRate() <= self::MAX_ABANDON_RATE,
        ];
    }
}
