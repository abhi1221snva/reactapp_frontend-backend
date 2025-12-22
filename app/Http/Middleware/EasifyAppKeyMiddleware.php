<?php

namespace App\Http\Middleware;

use Closure;

class EasifyAppKeyMiddleware
{
    public function handle($request, Closure $next)
    {
        $appKey = $request->header('X-Easify-App-Key');

        if ($appKey !== env('EASIFY_APP_KEY')) {
            return response()->json([
                'message' => 'Invalid or missing X-Easify-App-Key'
            ], 401);
        }

        return $next($request);
    }
}
