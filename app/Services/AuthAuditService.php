<?php

namespace App\Services;

use App\Model\Master\AuthEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AuthAuditService
{
    /**
     * Log an authentication event.
     *
     * Event types:
     *   login.success, login.failed, login.locked, logout,
     *   2fa.enabled, 2fa.disabled, 2fa.verified, 2fa.failed,
     *   password.changed, password.reset, session.revoked
     */
    public static function log(
        ?int   $userId,
        string $eventType,
        array  $metadata = [],
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        try {
            AuthEvent::create([
                'user_id'    => $userId,
                'event_type' => $eventType,
                'ip_address' => $ip ?? request()->ip(),
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : substr((string) request()->userAgent(), 0, 500),
                'metadata'   => !empty($metadata) ? $metadata : null,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Never let audit logging break the main flow
            Log::warning('AuthAuditService::log failed', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
