<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Twilio\Security\RequestValidator;
use Illuminate\Support\Facades\Log;

class TwilioWebhookMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Skip validation in local/testing environments
        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $signature = $request->header('X-Twilio-Signature', '');
        $authToken = env('TWILIO_AUTH_TOKEN', '');

        if (!$authToken) {
            Log::error('TwilioWebhookMiddleware: TWILIO_AUTH_TOKEN not set');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        // Build full URL exactly as Twilio does
        $url = $request->fullUrl();

        // POST params for signature validation
        $params = $request->isMethod('POST') ? $request->post() : [];

        $validator = new RequestValidator($authToken);

        if (!$validator->validate($signature, $url, $params)) {
            Log::warning('Invalid Twilio webhook signature', [
                'ip'  => $request->ip(),
                'url' => $url,
            ]);
            return response()->json(['error' => 'Invalid Twilio signature'], 403);
        }

        return $next($request);
    }
}
