<?php

namespace App\Http\Helper;

use App\Model\Master\RefreshToken;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class JwtToken
{
    /**
     * Access token TTL in seconds (30 minutes).
     */
    private const ACCESS_TTL = 30 * 60;

    /**
     * Refresh token TTL in days.
     */
    private const REFRESH_TTL_DAYS = 7;

    public static function createToken($userId)
    {
        $payload = [
            'iss' => "lumen-jwt",
            'sub' => $userId,
            'iat' => time(),
            'exp' => time() + self::ACCESS_TTL,
        ];

        $secret    = env('JWT_SECRET');
        $algorithm = 'HS256';
        $token     = JWT::encode($payload, $secret, $algorithm);

        return [
            $token,
            \Illuminate\Support\Carbon::parse($payload['exp'])->toIso8601ZuluString(),
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
            'exp'             => time() + self::ACCESS_TTL,
            'client_override' => $clientOverride,
        ];

        $token = JWT::encode($payload, env('JWT_SECRET'), 'HS256');

        return [
            $token,
            \Illuminate\Support\Carbon::parse($payload['exp'])->toIso8601ZuluString(),
        ];
    }

    /**
     * Blacklist a token so it cannot be used again.
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
            // Token already invalid or malformed — nothing to blacklist
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

    // ── Refresh token methods ─────────────────────────────────────────────

    /**
     * Generate a cryptographically secure refresh token string.
     */
    public static function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(32)); // 64-char hex
    }

    /**
     * Create a refresh token DB record and return the plaintext token + expiry.
     */
    public static function createRefreshToken(int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $plainToken = self::generateRefreshToken();
        $familyId   = (string) Str::uuid();
        $expiresAt  = Carbon::now()->addDays(self::REFRESH_TTL_DAYS);

        RefreshToken::create([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $plainToken),
            'family_id'  => $familyId,
            'expires_at' => $expiresAt,
            'revoked'    => false,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
        ]);

        return [$plainToken, $expiresAt->toIso8601ZuluString()];
    }

    /**
     * Rotate a refresh token: consume old, issue new in the same family.
     *
     * Returns [userId, newPlainToken, newExpiresAt] or null on failure.
     * If a revoked token is reused, the entire family is revoked (replay attack detection).
     */
    public static function rotateRefreshToken(string $plainToken, ?string $ip = null, ?string $ua = null): ?array
    {
        $hash   = hash('sha256', $plainToken);
        $record = RefreshToken::where('token_hash', $hash)->first();

        if (!$record) {
            return null;
        }

        // Replay attack: if already revoked, revoke entire family
        if ($record->revoked) {
            RefreshToken::where('family_id', $record->family_id)
                ->update(['revoked' => true]);
            return null;
        }

        // Expired
        if ($record->expires_at->isPast()) {
            $record->update(['revoked' => true]);
            return null;
        }

        // Revoke old token
        $record->update(['revoked' => true]);

        // Issue new token in the same family
        $newPlain  = self::generateRefreshToken();
        $expiresAt = Carbon::now()->addDays(self::REFRESH_TTL_DAYS);

        RefreshToken::create([
            'user_id'    => $record->user_id,
            'token_hash' => hash('sha256', $newPlain),
            'family_id'  => $record->family_id,
            'expires_at' => $expiresAt,
            'revoked'    => false,
            'ip_address' => $ip,
            'user_agent' => $ua ? substr($ua, 0, 500) : null,
        ]);

        return [(int) $record->user_id, $newPlain, $expiresAt->toIso8601ZuluString()];
    }

    /**
     * Revoke all refresh tokens for a user (e.g. on logout).
     */
    public static function revokeAllRefreshTokens(int $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }
}
