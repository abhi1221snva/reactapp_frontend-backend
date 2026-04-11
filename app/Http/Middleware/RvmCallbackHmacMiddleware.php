<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RvmCallbackHmacMiddleware
 *
 * Verifies that incoming requests to /rvm-callback-cdr carry a valid
 * HMAC-SHA256 signature over the raw request body, using the shared
 * secret configured in RVM_CALLBACK_SECRET.
 *
 * Expected headers:
 *   X-Rvm-Signature: hex(hmac_sha256($secret, $rawBody))
 *
 * Optional replay protection:
 *   X-Rvm-Timestamp: unix seconds — rejected if older than 300s.
 *
 * Returns 401 on missing/invalid signature, 500 if not configured.
 */
class RvmCallbackHmacMiddleware
{
    /** Maximum allowed drift for X-Rvm-Timestamp (seconds). */
    private const MAX_CLOCK_SKEW = 300;

    public function handle(Request $request, Closure $next)
    {
        $secret = env('RVM_CALLBACK_SECRET', '');

        if ($secret === '') {
            Log::error('RvmCallbackHmacMiddleware: RVM_CALLBACK_SECRET not set');
            return response()->json([
                'success' => false,
                'message' => 'Callback verification not configured.',
                'data'    => [],
            ], 500);
        }

        $providedSignature = (string) $request->header('X-Rvm-Signature', '');
        if ($providedSignature === '') {
            return $this->reject('Missing X-Rvm-Signature header.', $request);
        }

        // Optional timestamp replay window — applied only when header present.
        $providedTimestamp = $request->header('X-Rvm-Timestamp');
        if ($providedTimestamp !== null && $providedTimestamp !== '') {
            if (!ctype_digit((string) $providedTimestamp)) {
                return $this->reject('Malformed X-Rvm-Timestamp.', $request);
            }
            $skew = abs(time() - (int) $providedTimestamp);
            if ($skew > self::MAX_CLOCK_SKEW) {
                return $this->reject('Stale timestamp.', $request);
            }
        }

        $rawBody = (string) $request->getContent();
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expectedSignature, $providedSignature)) {
            return $this->reject('Invalid signature.', $request);
        }

        return $next($request);
    }

    private function reject(string $reason, Request $request)
    {
        Log::warning('RvmCallbackHmacMiddleware rejected request', [
            'reason' => $reason,
            'ip'     => $request->ip(),
            'path'   => $request->path(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized callback.',
            'data'    => [],
        ], 401);
    }
}
