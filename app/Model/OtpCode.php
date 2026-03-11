<?php

namespace App\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    /**
     * Use the master DB connection — same as OtpVerification.
     */
    protected $connection = 'master';

    protected $table = 'otp_codes';

    protected $fillable = [
        'user_id',
        'phone_or_email',
        'otp_code',
        'expires_at',
        'verified',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified'   => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isExpired(): bool
    {
        return Carbon::now()->isAfter($this->expires_at);
    }

    /**
     * Invalidate all previous unused codes for this user, then create a new one.
     * In local/testing environments the code is always '123456' for convenience.
     */
    public static function generateForUser(int $userId, string $phoneOrEmail): self
    {
        // Soft-invalidate any outstanding unverified codes for this user
        static::where('user_id', $userId)
            ->where('verified', false)
            ->update(['verified' => true]);

        $code = app()->environment('local', 'testing')
            ? '123456'
            : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return static::create([
            'user_id'        => $userId,
            'phone_or_email' => $phoneOrEmail,
            'otp_code'       => $code,
            'expires_at'     => Carbon::now()->addMinutes(5),
            'verified'       => false,
        ]);
    }
}
