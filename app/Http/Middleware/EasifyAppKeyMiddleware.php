<?php

namespace App\Http\Middleware;

use Closure;
use App\Model\User;

class EasifyAppKeyMiddleware
{
    public function handle($request, Closure $next)
    {
        // 1️⃣ App Key Validation (ALL APIs)
        $appKey = $request->header('X-Easify-App-Key');

        if ($appKey !== env('EASIFY_APP_KEY')) {
            return response()->json([
                'message' => 'Invalid or missing X-Easify-App-Key'
            ], 401);
        }

        // 2️⃣ Register API ke liye user token skip
        if ($request->is('register')) {
            return $next($request);
        }

        // 3️⃣ User Token Validation (ALL except register)
        $userToken = $request->header('X-Easify-User-Token');

        if (!$userToken) {
            return response()->json([
                'message' => 'Missing X-Easify-User-Token'
            ], 401);
        }

        $user = User::where('easify_user_uuid', $userToken)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid X-Easify-User-Token'
            ], 401);
        }

        // 4️⃣ Authenticated user request me attach (optional)
        $request->merge(['auth_user' => $user]);

        return $next($request);
    }
}
