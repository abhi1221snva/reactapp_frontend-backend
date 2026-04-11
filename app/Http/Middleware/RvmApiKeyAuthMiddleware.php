<?php

namespace App\Http\Middleware;

use App\Model\Master\Rvm\ApiKey;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * RvmApiKeyAuthMiddleware — auth for external /v1/rvm/* callers.
 *
 * Key format: rvm_live_{prefix}_{secret}   (prefix is the DB lookup key)
 *
 * Steps:
 *   1. Extract X-Api-Key header.
 *   2. Parse prefix + secret.
 *   3. Cache-lookup the rvm_api_keys row by prefix (60s TTL).
 *   4. Argon2 verify the raw key against key_hash.
 *   5. Populate $request->auth so downstream code has:
 *        ->parent_id   = client_id  (matches TenantIsolationMiddleware's contract)
 *        ->id          = 0          (no user)
 *        ->level       = 0          (not a portal user)
 *        ->api_key_id  = row id     (for rate limiting + audit)
 *
 * Rejects with 401 on any failure. Constant-time compare in password_verify.
 */
class RvmApiKeyAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $raw = $request->header('X-Api-Key');
        if (!$raw || !is_string($raw)) {
            return $this->unauthenticated('Missing X-Api-Key header');
        }

        $prefix = $this->extractPrefix($raw);
        if (!$prefix) {
            return $this->unauthenticated('Malformed API key');
        }

        // Short-lived cache so a burst of drops doesn't hammer master DB
        $cacheKey = "rvm:apikey:{$prefix}";
        $row = Cache::remember($cacheKey, 60, function () use ($prefix) {
            return ApiKey::on('master')->where('key_prefix', $prefix)->first();
        });

        if (!$row) {
            return $this->unauthenticated('Unknown API key');
        }
        if ($row->isRevoked()) {
            return $this->unauthenticated('API key revoked');
        }

        // password_verify is constant-time
        if (!password_verify($raw, $row->key_hash)) {
            return $this->unauthenticated('Invalid API key');
        }

        // Best-effort last-used tracking (not blocking request path)
        try {
            ApiKey::on('master')
                ->where('id', $row->id)
                ->update([
                    'last_used_at' => Carbon::now(),
                    'last_used_ip' => $request->ip(),
                ]);
        } catch (\Throwable $e) {
            // non-fatal
        }

        $request->auth = (object) [
            'id'         => 0,
            'parent_id'  => (int) $row->client_id,
            'level'      => 0,
            'api_key_id' => (int) $row->id,
            'scopes'     => $row->scopes ?? ['*'],
            'source'     => 'rvm_api_key',
        ];

        return $next($request);
    }

    private function extractPrefix(string $raw): ?string
    {
        // Expected: rvm_live_{prefix}_{secret} or rvm_test_{prefix}_{secret}
        if (!preg_match('/^rvm_(live|test)_([a-zA-Z0-9]{6,16})_/', $raw, $m)) {
            return null;
        }
        return $m[2];
    }

    private function unauthenticated(string $message)
    {
        return response()->json([
            'error' => [
                'type' => 'rvm.unauthenticated',
                'message' => $message,
                'status' => 401,
            ],
        ], 401);
    }
}
