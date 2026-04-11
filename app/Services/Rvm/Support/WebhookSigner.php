<?php

namespace App\Services\Rvm\Support;

/**
 * WebhookSigner — produces and verifies HMAC-SHA256 signatures for
 * outbound webhook deliveries.
 *
 * Wire format (Stripe-compatible):
 *   X-Rvm-Signature: t=1712845200,v1=a1b2c3...
 *
 * Signing is deterministic over:
 *   "{timestamp}.{raw_json_body}"
 *
 * Receivers MUST:
 *   1. Parse the t= and v1= components.
 *   2. Re-compute hash_hmac('sha256', "{t}.{raw_body}", shared_secret).
 *   3. Reject if hash_equals() fails (constant time).
 *   4. Reject if |now - t| > tolerance (replay protection — we recommend 5 min).
 */
class WebhookSigner
{
    private const ALGO = 'sha256';
    public const HEADER_NAME = 'X-Rvm-Signature';

    /**
     * Sign a raw JSON body with the endpoint secret.
     *
     * @return array{header: string, timestamp: int, signature: string}
     */
    public function sign(string $rawBody, string $secret, ?int $timestamp = null): array
    {
        $t = $timestamp ?? time();
        $signedPayload = $t . '.' . $rawBody;
        $signature = hash_hmac(self::ALGO, $signedPayload, $secret);

        return [
            'header'    => "t={$t},v1={$signature}",
            'timestamp' => $t,
            'signature' => $signature,
        ];
    }

    /**
     * Verify an incoming signature header — used by integration tests and
     * the /test endpoint that self-pings to confirm endpoint wiring.
     *
     * Returns true only if every component matches and timestamp is within
     * $toleranceSeconds of now.
     */
    public function verify(
        string $rawBody,
        string $secret,
        string $header,
        int $toleranceSeconds = 300,
    ): bool {
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m)) {
            return false;
        }
        $t = (int) $m[1];
        $received = $m[2];

        if (abs(time() - $t) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac(self::ALGO, $t . '.' . $rawBody, $secret);
        return hash_equals($expected, $received);
    }

    /**
     * Generate a cryptographically secure endpoint secret.
     * 48 bytes → 64 base64url chars.
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
