<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Stores email OTP records used in the registration flow.
 *
 * @property int    $id
 * @property string $email
 * @property string $otp
 * @property string $expires_at
 * @property bool   $verified
 * @property int    $attempts
 */
class EmailOtp extends Model
{
    protected $connection = 'master';
    protected $table      = 'email_otps';

    protected $fillable = [
        'email',
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

    /** True when the OTP has not yet expired. */
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

    /** Latest unverified OTP for the given email. */
    public static function latestForEmail(string $email): ?self
    {
        return static::where('email', $email)
            ->where('verified', false)
            ->latest('id')
            ->first();
    }
}
