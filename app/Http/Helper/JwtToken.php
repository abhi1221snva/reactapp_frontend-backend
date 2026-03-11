<?php

namespace App\Http\Helper;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class JwtToken
{
    public static function createToken($userId)
    {
        $payload = [
            'iss' => "lumen-jwt", // Issuer of the token
            'sub' => $userId,     // Subject of the token
            'iat' => time(),      // Issued at
            'exp' => time() + 14 * 60 * 60 // Expires in 14 hours
        ];

        $secret = env('JWT_SECRET');
        $algorithm = 'HS256';

        $token = JWT::encode($payload, $secret, $algorithm);

        return [
            $token,
            Carbon::parse($payload['exp'])->toIso8601ZuluString()
        ];
    }

    /**
     * Create a JWT for the admin's own user but scoped to a different client's DB.
     * The client_override claim is read by JwtMiddleware to swap parent_id for superadmins.
     */
    public static function createTokenWithOverride(int $userId, int $clientOverride): array
    {
        $payload = [
            'iss'             => "lumen-jwt",
            'sub'             => $userId,
            'iat'             => time(),
            'exp'             => time() + 14 * 60 * 60,
            'client_override' => $clientOverride,
        ];

        $token = JWT::encode($payload, env('JWT_SECRET'), 'HS256');

        return [
            $token,
            Carbon::parse($payload['exp'])->toIso8601ZuluString(),
        ];
    }

    /**
     * Blacklist a token so it cannot be used again.
     * TTL is set to match the token remaining lifetime so the Redis key
     * is automatically cleaned up once the token would have expired anyway.
     */
    public static function blacklist(string $token): void
    {
        try {
            $credentials = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
            $ttl = max(0, (int) $credentials->exp - time());
            if ($ttl > 0) {
                Cache::put(
                    'jwt_blacklist:' . hash('sha256', $token),
                    true,
                    $ttl
                );
            }
        } catch (\Exception $e) {
            // Token already invalid or malformed - nothing to blacklist
        }
    }

    /**
     * Check if a token is blacklisted (i.e. has been explicitly revoked).
     */
    public static function isBlacklisted(string $token): bool
    {
        return (bool) Cache::get(
            'jwt_blacklist:' . hash('sha256', $token)
        );
    }
}
