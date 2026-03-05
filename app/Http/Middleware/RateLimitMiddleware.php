<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Simple Redis/Cache-backed rate limiter.
 * Usage in routes: 'throttle:10,1' = 10 requests per 1 minute
 */
class RateLimitMiddleware
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 10, int $decayMinutes = 1)
    {
        $key = 'rate_limit:' . sha1($request->ip() . '|' . $request->path());
        $attempts = (int) Cache::get($key, 0);

        if ($attempts >= $maxAttempts) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again in ' . $decayMinutes . ' minute(s).',
            ], 429);
        }

        Cache::put($key, $attempts + 1, now()->addMinutes($decayMinutes));

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - $attempts - 1));

        return $response;
    }
}
