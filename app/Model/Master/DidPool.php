<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class DidPool extends Model
{
    protected $connection = 'master';
    protected $table      = 'did_pool';

    const STATUS_FREE     = 'free';
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_RESERVED = 'reserved';
    const STATUS_BLOCKED  = 'blocked';
    const STATUS_COOLDOWN = 'cooldown';

    const TYPE_TRIAL  = 'trial';
    const TYPE_MANUAL = 'manual';

    const COOLDOWN_HOURS = 24;

    protected $fillable = [
        'phone_number', 'status', 'assigned_client_id', 'provider',
        'provider_sid', 'area_code', 'country_code', 'number_type',
        'capabilities', 'assignment_type', 'assigned_at', 'released_at',
        'cooldown_until', 'blocked_reason', 'blocked_by', 'notes',
    ];

    protected $casts = [
        'capabilities'   => 'array',
        'assigned_at'    => 'datetime',
        'released_at'    => 'datetime',
        'cooldown_until' => 'datetime',
    ];

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeFree($query)
    {
        return $query->where('status', self::STATUS_FREE);
    }

    public function scopeAssigned($query)
    {
        return $query->where('status', self::STATUS_ASSIGNED);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_FREE)
            ->where(function ($q) {
                $q->whereNull('cooldown_until')
                  ->orWhere('cooldown_until', '<', now());
            });
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('assigned_client_id', $clientId);
    }

    // ── Relations ───────────────────────────────────────────────────────

    public function client()
    {
        return $this->belongsTo(Client::class, 'assigned_client_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function isAssignable(): bool
    {
        if ($this->status !== self::STATUS_FREE) return false;
        if ($this->cooldown_until && $this->cooldown_until->isFuture()) return false;
        return true;
    }
}
