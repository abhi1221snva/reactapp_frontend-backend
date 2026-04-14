<?php

namespace App\Http\Middleware;

use App\Model\Merchant\Merchants;
use App\Http\Helper\JwtToken;
use Closure;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;

class MerchantJwtMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token not provided.'], 401);
        }

        try {
            $credentials = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
        } catch (ExpiredException $e) {
            return response()->json(['success' => false, 'message' => 'Token expired.'], 401);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Invalid token.'], 401);
        }

        if (JwtToken::isBlacklisted($token)) {
            return response()->json(['success' => false, 'message' => 'Token revoked.'], 401);
        }

        $merchant = Merchants::find($credentials->sub);

        if (!$merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant account not found.'], 401);
        }

        $request->attributes->set('merchant', $merchant);
        return $next($request);
    }
}
