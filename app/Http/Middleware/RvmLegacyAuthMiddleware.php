<?php

namespace App\Http\Middleware;

use App\Model\Master\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RvmLegacyAuthMiddleware
 *
 * Drop-in auth for the legacy /ringless-voicemail-drop* and /rvm-* routes.
 *
 * Before this middleware existed these routes sat outside every middleware
 * group and relied on an inline string comparison against a hardcoded
 * "Easify => bc6c" key (see audit finding S1). This middleware:
 *
 *   1. Reads `api_key` from the request (JSON body, form body, or query).
 *   2. Looks it up against clients.api_key (master DB) with a
 *      constant-time hash_equals check.
 *   3. Populates $request->auth with a Client-like object so downstream
 *      controllers that read $request->auth->parent_id continue to work.
 *
 * Rejects with HTTP 401 on missing / unknown / revoked keys.
 */
class RvmLegacyAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $providedKey = $this->extractKey($request);

        if ($providedKey === null || $providedKey === '') {
            return $this->unauthenticated('API key missing.');
        }

        // Pull only what we need and only non-deleted clients.
        $clients = Client::where('is_deleted', 0)
            ->whereNotNull('api_key')
            ->get(['id', 'company_name', 'api_key']);

        $matched = null;
        foreach ($clients as $c) {
            if (!empty($c->api_key) && hash_equals((string) $c->api_key, (string) $providedKey)) {
                $matched = $c;
                break;
            }
        }

        if (!$matched) {
            Log::warning('RvmLegacyAuthMiddleware: invalid api_key', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);
            return $this->unauthenticated('Invalid API key.');
        }

        // Downstream controllers (TenantAware, legacy code) read parent_id.
        $request->auth = (object) [
            'id'        => 0,
            'parent_id' => (int) $matched->id,
            'level'     => 0,
            'company'   => $matched->company_name,
            'source'    => 'rvm_legacy_api_key',
        ];

        return $next($request);
    }

    /**
     * Look for the key on the body first, then the query string.
     */
    private function extractKey(Request $request): ?string
    {
        $candidates = [
            $request->input('api_key'),
            $request->header('X-Api-Key'),
            $request->query('api_key'),
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    private function unauthenticated(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => [],
        ], 401);
    }
}
