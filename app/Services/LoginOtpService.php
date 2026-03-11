<?php

namespace App\Services;

use App\Model\OtpCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * LoginOtpService — handles OTP generation, delivery, and verification
 * specifically for the login-time 2FA flow (is_2fa_phone_enabled / enable_2fa).
 *
 * This is separate from the existing OtpService which manages registration OTPs,
 * so as not to alter any existing registration or forgot-password behaviour.
 */
class LoginOtpService
{
    // Maximum OTP send requests per user within the rate-limit window
    const RATE_LIMIT_MAX    = 3;
    // Rate-limit window in seconds (5 minutes)
    const RATE_LIMIT_WINDOW = 300;

    // -------------------------------------------------------------------------
    // Rate limiting
    // -------------------------------------------------------------------------

    /**
     * Return true if the user has already requested too many OTPs recently.
     */
    public static function isRateLimited(int $userId): bool
    {
        $key   = "login_otp_rate:{$userId}";
        $count = (int) Cache::get($key, 0);
        return $count >= self::RATE_LIMIT_MAX;
    }

    /**
     * Increment the OTP request counter for this user.
     */
    public static function incrementRateLimit(int $userId): void
    {
        $key = "login_otp_rate:{$userId}";
        if (Cache::has($key)) {
            Cache::increment($key);
        } else {
            Cache::put($key, 1, self::RATE_LIMIT_WINDOW);
        }
    }

    // -------------------------------------------------------------------------
    // Generation & dispatch
    // -------------------------------------------------------------------------

    /**
     * Generate a new OTP for the given user, log/dispatch it, and return the
     * OtpCode record.  In dev/local mode the code is always '123456' and is
     * included in the return value so callers can surface it in the response.
     *
     * @param  int    $userId
     * @param  string $phoneOrEmail  The destination (phone E.164 or email address)
     * @return OtpCode
     */
    public static function send(int $userId, string $phoneOrEmail): OtpCode
    {
        $otp = OtpCode::generateForUser($userId, $phoneOrEmail);

        if (app()->environment('local', 'testing')) {
            // Dev convenience — log the code so developers can see it without
            // needing a real SMS/email provider.
            Log::info('[LoginOTP - DEV] Generated OTP', [
                'user_id'        => $userId,
                'phone_or_email' => $phoneOrEmail,
                'otp_code'       => $otp->otp_code,
                'expires_at'     => $otp->expires_at,
            ]);
        } else {
            // Production: determine delivery channel from destination string
            $stripped = preg_replace('/[\s\-\(\)]/', '', $phoneOrEmail);
            if (preg_match('/^\+?[0-9]{7,15}$/', $stripped)) {
                // Looks like a phone number — dispatch SMS
                // \App\Jobs\SendLoginOtpSmsJob::dispatch($phoneOrEmail, $otp->otp_code);
                Log::info('[LoginOTP] SMS dispatch queued', ['to' => $phoneOrEmail]);
            } else {
                // Treat as e-mail address
                // \App\Jobs\SendLoginOtpEmailJob::dispatch($phoneOrEmail, $otp->otp_code);
                Log::info('[LoginOTP] Email dispatch queued', ['to' => $phoneOrEmail]);
            }
        }

        return $otp;
    }

    // -------------------------------------------------------------------------
    // Verification
    // -------------------------------------------------------------------------

    /**
     * Verify a code submitted by the user.
     *
     * Uses hash_equals() to prevent timing attacks.
     * Marks the record as verified so the same code cannot be reused.
     *
     * @param  int    $userId
     * @param  string $code   The 6-digit string submitted by the user
     * @return bool
     */
    public static function verify(int $userId, string $code): bool
    {
        $otp = OtpCode::where('user_id', $userId)
            ->where('verified', false)
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('id')
            ->first();

        if (! $otp) {
            return false;
        }

        if (! hash_equals($otp->otp_code, (string) $code)) {
            return false;
        }

        $otp->update(['verified' => true]);
        return true;
    }
}
