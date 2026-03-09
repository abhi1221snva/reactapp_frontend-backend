<?php

namespace App\Http\Middleware;

use App\Model\Master\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogMiddleware
{
    // Keys whose values must never be stored
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'token', 'secret',
        'api_key', 'auth_token', 'access_token', 'refresh_token',
        'card_number', 'cvv', 'ssn',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only log mutating methods
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        // Only log admin-level users (level 7+)
        if (empty($request->auth) || $request->auth->level < 7) {
            return $response;
        }

        try {
            AuditLog::create([
                'user_id'    => $request->auth->id,
                'client_id'  => $request->auth->parent_id ?? $request->auth->id,
                'user_level' => $request->auth->level,
                'method'     => $request->method(),
                'path'       => substr($request->path(), 0, 500),
                'payload'    => $this->sanitizePayload($request->except(self::REDACTED_KEYS)),
                'ip'         => $request->ip(),
                'created_at' => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Non-blocking — log the error but never fail the request
            Log::error('[AuditLog] Failed to write audit entry', [
                'error' => $e->getMessage(),
                'path'  => $request->path(),
            ]);
        }

        return $response;
    }

    private function sanitizePayload(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizePayload($value);
            }
        }
        return $data;
    }
}
