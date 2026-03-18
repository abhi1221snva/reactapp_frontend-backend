<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EmailSetting extends Model
{
    public $timestamps = true;
    protected $table   = 'crm_smtp_setting';

    protected $fillable = [
        'mail_driver',
        'mail_host',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_port',
        'sender_email',
        'sender_name',
        'send_email_via',
        'mail_type',
        'driver',
        'status',
        'meta_json',
    ];

    protected $hidden = ['mail_password'];

    protected $casts = [
        'status' => 'integer',
    ];

    // ── Password encryption ────────────────────────────────────────────────────
    public function setMailPasswordAttribute(string $value): void
    {
        // Only encrypt if not already encrypted (avoids double-encryption on re-save)
        if ($value && !$this->isEncrypted($value)) {
            $this->attributes['mail_password'] = Crypt::encryptString($value);
        } else {
            $this->attributes['mail_password'] = $value;
        }
    }

    public function getMailPasswordAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value; // legacy plain-text fallback
        }
    }

    // ── meta_json accessor ─────────────────────────────────────────────────────
    public function getMetaAttribute(): array
    {
        return $this->meta_json ? (json_decode($this->meta_json, true) ?? []) : [];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    /**
     * Rudimentary check: Laravel encrypted strings always start with 'eyJ'
     * (base64-encoded JSON payload) and are much longer than typical passwords.
     */
    private function isEncrypted(string $value): bool
    {
        return strlen($value) > 100 && str_starts_with(base64_decode(substr($value, 0, 12), true) ?: '', '{');
    }

    /**
     * Return a decrypted plain-text password for use in SMTP transport.
     * Guards against exceptions so callers never crash on legacy rows.
     */
    public function plainPassword(): ?string
    {
        return $this->mail_password; // accessor already decrypts
    }
}
