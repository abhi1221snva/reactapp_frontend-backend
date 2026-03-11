<?php

namespace App\Http\Controllers;

use App\Services\StructuredLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * System health and observability endpoints.
 * Admin access only (level >= 7).
 */
class SystemHealthController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if ($request->auth->level < 7) {
            abort(403, 'Admin access required');
        }
    }

    /**
     * GET /system/health
     * Returns overall system health status.
     */
    public function health(Request $request)
    {
        $this->requireAdmin($request);

        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
            'queue'    => $this->checkQueueHealth(),
            'disk'     => $this->checkDiskSpace(),
        ];

        $allHealthy = collect($checks)->every(fn($c) => $c['healthy']);
        $status     = $allHealthy ? 'healthy' : 'degraded';

        return response()->json([
            'status'     => true,
            'health'     => $status,
            'checks'     => $checks,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /system/queue-stats
     * Returns queue depth and job statistics.
     */
    public function queueStats(Request $request)
    {
        $this->requireAdmin($request);

        $queues = ['default', 'twilio', 'metrics', 'high', 'low'];
        $stats  = [];

        foreach ($queues as $queue) {
            try {
                $pending  = (int) Redis::llen("queues:{$queue}");
                $reserved = (int) Redis::zcard("queues:{$queue}:reserved");
                $delayed  = (int) Redis::zcard("queues:{$queue}:delayed");
                $failed   = (int) Redis::llen("queues:{$queue}:failed");

                $stats[$queue] = [
                    'pending'  => $pending,
                    'reserved' => $reserved,
                    'delayed'  => $delayed,
                    'failed'   => $failed,
                    'total'    => $pending + $reserved + $delayed,
                ];
            } catch (\Exception $e) {
                $stats[$queue] = ['error' => $e->getMessage()];
            }
        }

        return response()->json(['status' => true, 'data' => $stats]);
    }

    /**
     * GET /system/error-trends
     * Returns error/warning count trends.
     */
    public function errorTrends(Request $request)
    {
        $this->requireAdmin($request);
        $clientId = $request->auth->parent_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'errors_trend'   => StructuredLogger::getMetricTrend('errors_per_hour', 24, $clientId),
                'warnings_trend' => StructuredLogger::getMetricTrend('warnings_per_hour', 24, $clientId),
                'top_events'     => StructuredLogger::getEventCounts(24, $clientId),
            ],
        ]);
    }

    /**
     * GET /system/performance-metrics
     * Returns response time and performance data.
     */
    public function performanceMetrics(Request $request)
    {
        $this->requireAdmin($request);
        $clientId = $request->auth->parent_id;

        try {
            $raw   = Redis::lrange("slog:response_times:{$clientId}", 0, 99) ?: [];
            $times = array_map(fn($r) => json_decode($r, true), $raw);
            $times = array_filter($times);

            $byEndpoint = [];
            foreach ($times as $t) {
                $ep = $t['endpoint'] ?? 'unknown';
                $byEndpoint[$ep][] = $t['ms'] ?? 0;
            }

            $summary = [];
            foreach ($byEndpoint as $ep => $msList) {
                $summary[] = [
                    'endpoint' => $ep,
                    'avg_ms'   => round(array_sum($msList) / count($msList), 1),
                    'max_ms'   => max($msList),
                    'min_ms'   => min($msList),
                    'count'    => count($msList),
                ];
            }
            usort($summary, fn($a, $b) => $b['avg_ms'] - $a['avg_ms']);
        } catch (\Exception $e) {
            $summary = [];
        }

        return response()->json(['status' => true, 'data' => $summary]);
    }

    // ─── Health Checks ───────────────────────────────────────────────────────────

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = round((microtime(true) - $start) * 1000, 1);
            return ['healthy' => true, 'latency_ms' => $ms];
        } catch (\Exception $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $ms = round((microtime(true) - $start) * 1000, 1);
            return ['healthy' => true, 'latency_ms' => $ms];
        } catch (\Exception $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkQueueHealth(): array
    {
        try {
            $failedJobs = (int) Redis::llen('queues:failed');
            $pending    = (int) Redis::llen('queues:default');
            return [
                'healthy'     => $failedJobs < 100,
                'failed_jobs' => $failedJobs,
                'pending'     => $pending,
            ];
        } catch (\Exception $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkDiskSpace(): array
    {
        try {
            $free  = disk_free_space('/');
            $total = disk_total_space('/');
            $pct   = round(($total - $free) / $total * 100, 1);
            return [
                'healthy'  => $pct < 85,
                'used_pct' => $pct,
                'free_gb'  => round($free / 1024 / 1024 / 1024, 1),
            ];
        } catch (\Exception $e) {
            return ['healthy' => true, 'error' => 'disk check unavailable'];
        }
    }
}
