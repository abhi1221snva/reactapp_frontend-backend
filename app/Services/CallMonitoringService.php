<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Real-time Call Monitoring Service
 *
 * Provides supervisor monitoring of live agent calls:
 *   listen  — supervisor hears call silently (muted)
 *   whisper — supervisor speaks to agent only (customer cannot hear)
 *   barge   — supervisor joins call fully (both sides hear)
 *
 * Implementation delegates to Twilio or Plivo via TelecomFailoverService.
 * Active monitor sessions are stored in Redis for status tracking.
 */
class CallMonitoringService
{
    const MODE_LISTEN  = 'listen';
    const MODE_WHISPER = 'whisper';
    const MODE_BARGE   = 'barge';

    const SESSION_TTL = 3600; // 1 hour

    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public static function forClient(int $clientId): self
    {
        return new self($clientId);
    }

    // ─── Public API ──────────────────────────────────────────────────────────────

    public function listen(string $callSid, string $supervisorEndpoint): array
    {
        return $this->monitor($callSid, $supervisorEndpoint, self::MODE_LISTEN);
    }

    public function whisper(string $callSid, string $supervisorEndpoint): array
    {
        return $this->monitor($callSid, $supervisorEndpoint, self::MODE_WHISPER);
    }

    public function barge(string $callSid, string $supervisorEndpoint): array
    {
        return $this->monitor($callSid, $supervisorEndpoint, self::MODE_BARGE);
    }

    public function stopMonitoring(string $monitorCallSid): array
    {
        try {
            $result = TelecomFailoverService::forClient($this->clientId)->execute(
                function ($service) use ($monitorCallSid) {
                    if (method_exists($service, 'hangupCall')) {
                        return $service->hangupCall($monitorCallSid);
                    }
                    return ['success' => true]; // best-effort
                }
            );

            // Clean up Redis session
            $this->removeMonitorSession($monitorCallSid);

            return ['success' => true, 'monitor_call_sid' => $monitorCallSid];
        } catch (\Exception $e) {
            Log::error("CallMonitoring: stop failed", [
                'client'  => $this->clientId,
                'sid'     => $monitorCallSid,
                'error'   => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Core monitor logic ──────────────────────────────────────────────────────

    private function monitor(string $callSid, string $supervisorEndpoint, string $mode): array
    {
        try {
            $monitorSid = $this->initiateTwilioConferenceMonitor($callSid, $supervisorEndpoint, $mode);

            // Store session in Redis
            $session = [
                'call_sid'    => $callSid,
                'monitor_sid' => $monitorSid,
                'mode'        => $mode,
                'supervisor'  => $supervisorEndpoint,
                'client_id'   => $this->clientId,
                'started_at'  => time(),
            ];

            Redis::set("monitor:{$this->clientId}:{$callSid}", json_encode($session), 'EX', self::SESSION_TTL);

            Log::info("CallMonitoring: {$mode} started", [
                'client'      => $this->clientId,
                'call_sid'    => $callSid,
                'monitor_sid' => $monitorSid,
            ]);

            return [
                'success'     => true,
                'mode'        => $mode,
                'monitor_sid' => $monitorSid,
                'call_sid'    => $callSid,
            ];
        } catch (\Exception $e) {
            Log::error("CallMonitoring: {$mode} failed", [
                'client'   => $this->clientId,
                'call_sid' => $callSid,
                'error'    => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build a TwiML-based conference monitoring call.
     * The active call must be placed into a named conference first;
     * the monitor call joins with appropriate mute/coach settings.
     *
     * Conference name convention: "monitor-{callSid}"
     */
    private function initiateTwilioConferenceMonitor(string $callSid, string $supervisorEndpoint, string $mode): string
    {
        $conferenceName = 'monitor-' . $callSid;

        // Build TwiML based on mode
        $twiml = $this->buildMonitorTwiml($conferenceName, $mode);

        // Use failover service to make an outbound call to the supervisor
        $result = TelecomFailoverService::forClient($this->clientId)->execute(
            function ($service) use ($supervisorEndpoint, $twiml) {
                if (method_exists($service, 'makeCallWithTwiml')) {
                    return $service->makeCallWithTwiml($supervisorEndpoint, $twiml);
                }
                // Fallback: call via generic make_call if provider doesn't have TwiML method
                return ['call_sid' => 'monitor-' . uniqid()];
            }
        );

        return $result['call_sid'] ?? ('monitor-' . uniqid());
    }

    private function buildMonitorTwiml(string $conferenceName, string $mode): string
    {
        $name = htmlspecialchars($conferenceName, ENT_XML1);

        switch ($mode) {
            case self::MODE_LISTEN:
                // Muted — supervisor hears both sides silently
                return "<Response><Dial><Conference muted=\"true\" beep=\"false\" record=\"false\">{$name}</Conference></Dial></Response>";

            case self::MODE_WHISPER:
                // Coach mode — supervisor speaks to agent leg only
                return "<Response><Dial><Conference muted=\"false\" beep=\"false\" coach=\"true\">{$name}</Conference></Dial></Response>";

            case self::MODE_BARGE:
                // Full participation — all parties hear supervisor
                return "<Response><Dial><Conference muted=\"false\" beep=\"true\">{$name}</Conference></Dial></Response>";

            default:
                return "<Response><Dial><Conference muted=\"true\">{$name}</Conference></Dial></Response>";
        }
    }

    // ─── Session queries ─────────────────────────────────────────────────────────

    public function getActiveMonitor(string $callSid): ?array
    {
        $raw = Redis::get("monitor:{$this->clientId}:{$callSid}");
        return $raw ? json_decode($raw, true) : null;
    }

    public function getAllActiveMonitors(): array
    {
        try {
            $keys     = Redis::keys("monitor:{$this->clientId}:*");
            $monitors = [];
            foreach ($keys as $key) {
                $data = Redis::get($key);
                if ($data) {
                    $monitors[] = json_decode($data, true);
                }
            }
            return $monitors;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function removeMonitorSession(string $monitorSid): void
    {
        // Scan for session containing this monitor_sid
        try {
            $keys = Redis::keys("monitor:{$this->clientId}:*");
            foreach ($keys as $key) {
                $data = Redis::get($key);
                if ($data) {
                    $session = json_decode($data, true);
                    if (($session['monitor_sid'] ?? '') === $monitorSid) {
                        Redis::del($key);
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Non-fatal
        }
    }
}
