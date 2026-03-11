<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\PlivoService;
use Illuminate\Support\Facades\Log;

class PlivoWebhookMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Skip validation in local/testing environments
        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $signature = $request->header('X-Plivo-Signature', '');
        $authToken = env('PLIVO_AUTH_TOKEN', '');

        if (!$authToken) {
            Log::error('PlivoWebhookMiddleware: PLIVO_AUTH_TOKEN not set');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        // Only validate if signature header is present (Plivo may not always send it)
        if ($signature) {
            $params = $request->isMethod('POST') ? $request->post() : [];

            if (!PlivoService::validateSignature($signature, $params, $authToken)) {
                Log::warning('Invalid Plivo webhook signature', [
                    'ip'  => $request->ip(),
                    'url' => $request->fullUrl(),
                ]);
                return response()->json(['error' => 'Invalid Plivo signature'], 403);
            }
        }

        return $next($request);
    }
}
