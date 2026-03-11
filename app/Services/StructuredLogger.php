<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Structured Logger — emits JSON log lines for easy parsing/aggregation.
 *
 * Every log entry includes:
 *   - timestamp (ISO 8601)
 *   - level (debug/info/warning/error/critical)
 *   - event (dot-notation event name e.g. "dialer.call.started")
 *   - client_id
 *   - context (arbitrary key-value pairs)
 *   - request_id (set per request)
 *
 * Usage:
 *   StructuredLogger::info('dialer.call.started', ['call_sid' => $sid], $clientId);
 *   StructuredLogger::error('telecom.failover', ['from' => 'twilio', 'to' => 'plivo'], $clientId);
 */
class StructuredLogger
{
    /** Log a debug event */
    public static function debug(string $event, array $context = [], int $clientId = 0): void
    {
        self::write('debug', $event, $context, $clientId);
    }

    /** Log an info event */
    public static function info(string $event, array $context = [], int $clientId = 0): void
    {
        self::write('info', $event, $context, $clientId);
        self::incrementEventCounter($event, $clientId);
    }

    /** Log a warning event */
    public static function warning(string $event, array $context = [], int $clientId = 0): void
    {
        self::write('warning', $event, $context, $clientId);
        self::incrementEventCounter($event, $clientId);
        self::recordMetric('warnings_per_hour', 1, $clientId);
    }

    /** Log an error event */
    public static function error(string $event, array $context = [], int $clientId = 0): void
    {
        self::write('error', $event, $context, $clientId);
        self::incrementEventCounter($event, $clientId);
        self::recordMetric('errors_per_hour', 1, $clientId);
    }

    /** Log a critical event + send Redis alert */
    public static function critical(string $event, array $context = [], int $clientId = 0): void
    {
        self::write('critical', $event, $context, $clientId);
        self::recordMetric('critical_per_hour', 1, $clientId);
        self::publishAlert($event, $context, $clientId);
    }

    // ─── Core write ─────────────────────────────────────────────────────────────

    private static function write(string $level, string $event, array $context, int $clientId): void
    {
        $entry = [
            'ts'         => now()->toIso8601String(),
            'level'      => $level,
            'event'      => $event,
            'client_id'  => $clientId ?: 0,
            'request_id' => self::getRequestId(),
            'env'        => env('APP_ENV', 'production'),
        ] + $context; // merge context at top level for easy filtering

        // Write structured JSON line to the application log
        Log::$level(json_encode($entry));
    }

    // ─── Redis metrics ───────────────────────────────────────────────────────────

    /** Increment event counter (used for observability dashboard) */
    private static function incrementEventCounter(string $event, int $clientId): void
    {
        try {
            $hourKey = 'slog:events:' . date('YmdH');
            Redis::hincrby($hourKey, "{$clientId}:{$event}", 1);
            Redis::expire($hourKey, 3600 * 25); // keep 25 hours
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    /** Record a numeric metric */
    public static function recordMetric(string $metric, float $value, int $clientId = 0): void
    {
        try {
            $hourKey = 'slog:metrics:' . date('YmdH');
            Redis::hincrbyfloat($hourKey, "{$clientId}:{$metric}", $value);
            Redis::expire($hourKey, 3600 * 25);
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    /** Record response time for an endpoint */
    public static function recordResponseTime(string $endpoint, float $ms, int $clientId = 0): void
    {
        try {
            $key = "slog:response_times:{$clientId}";
            Redis::lpush($key, json_encode(['endpoint' => $endpoint, 'ms' => $ms, 'ts' => time()]));
            Redis::ltrim($key, 0, 999); // keep last 1000
            Redis::expire($key, 3600);
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    /** Publish critical alert to Redis pub/sub */
    private static function publishAlert(string $event, array $context, int $clientId): void
    {
        try {
            Redis::publish('alerts', json_encode([
                'event'     => $event,
                'client_id' => $clientId,
                'context'   => $context,
                'ts'        => now()->toIso8601String(),
            ]));
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    // ─── Request tracking ────────────────────────────────────────────────────────

    private static ?string $requestId = null;

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function getRequestId(): string
    {
        return self::$requestId ?? substr(uniqid('req_', true), 0, 16);
    }

    // ─── Hourly stats query ──────────────────────────────────────────────────────

    /**
     * Get aggregated event counts for the last N hours.
     */
    public static function getEventCounts(int $hours = 24, int $clientId = 0): array
    {
        $result = [];
        for ($h = 0; $h < $hours; $h++) {
            $hourKey = 'slog:events:' . date('YmdH', strtotime("-{$h} hours"));
            try {
                $all = Redis::hgetall($hourKey) ?: [];
                foreach ($all as $key => $count) {
                    [$cId, $event] = explode(':', $key, 2);
                    if ($clientId > 0 && (int) $cId !== $clientId) continue;
                    $result[$event] = ($result[$event] ?? 0) + (int) $count;
                }
            } catch (\Exception $e) {
                // skip
            }
        }
        arsort($result);
        return $result;
    }

    /**
     * Get hourly error/warning counts.
     */
    public static function getMetricTrend(string $metric, int $hours = 24, int $clientId = 0): array
    {
        $trend = [];
        for ($h = $hours - 1; $h >= 0; $h--) {
            $hourKey = 'slog:metrics:' . date('YmdH', strtotime("-{$h} hours"));
            $label   = date('H:00', strtotime("-{$h} hours"));
            try {
                $value = (float) (Redis::hget($hourKey, "{$clientId}:{$metric}") ?? 0);
            } catch (\Exception $e) {
                $value = 0;
            }
            $trend[] = ['hour' => $label, 'value' => $value];
        }
        return $trend;
    }
}
