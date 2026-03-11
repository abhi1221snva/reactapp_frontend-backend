<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Stores phone OTP records used in the registration flow.
 *
 * @property int    $id
 * @property string $phone  E.164 format e.g. +14155551234
 * @property string $otp
 * @property string $expires_at
 * @property bool   $verified
 * @property int    $attempts
 */
class PhoneOtp extends Model
{
    protected $connection = 'master';
    protected $table      = 'phone_otps';

    protected $fillable = [
        'phone',
        'otp',
        'expires_at',
        'verified',
        'attempts',
    ];

    protected $casts = [
        'verified'   => 'boolean',
        'attempts'   => 'integer',
        'expires_at' => 'datetime',
    ];

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** True when the OTP is still usable. */
    public function isValid(): bool
    {
        return !$this->verified
            && $this->attempts < 5
            && Carbon::now()->lt($this->expires_at);
    }

    /** Increment the attempt counter and save. */
    public function recordAttempt(): void
    {
        $this->increment('attempts');
    }

    /** Mark this OTP as verified and save. */
    public function markVerified(): void
    {
        $this->verified = true;
        $this->save();
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Latest unverified OTP for the given phone number (E.164). */
    public static function latestForPhone(string $phone): ?self
    {
        return static::where('phone', $phone)
            ->where('verified', false)
            ->latest('id')
            ->first();
    }
}
