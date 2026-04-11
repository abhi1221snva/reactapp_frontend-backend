<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant RVM v2 pipeline mode.
 *
 * Absence of a row for a client_id == mode 'legacy' (safe default).
 * Primary key is client_id — one row per tenant — so upserts are cheap.
 *
 * @property int    $client_id
 * @property string $pipeline_mode
 * @property string|null $live_provider
 * @property int|null $live_daily_cap
 * @property \Carbon\Carbon|null $live_enabled_at
 * @property int|null $enabled_by_user_id
 * @property string|null $notes
 */
class TenantFlag extends Model
{
    protected $connection = 'master';
    protected $table      = 'rvm_tenant_flags';
    protected $primaryKey = 'client_id';
    public    $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'client_id',
        'pipeline_mode',
        'live_provider',
        'live_daily_cap',
        'live_enabled_at',
        'enabled_by_user_id',
        'notes',
    ];

    protected $casts = [
        'live_enabled_at' => 'datetime',
        'live_daily_cap'  => 'integer',
    ];

    public const MODE_LEGACY  = 'legacy';
    public const MODE_SHADOW  = 'shadow';
    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_LIVE    = 'live';

    public const ALL_MODES = [
        self::MODE_LEGACY,
        self::MODE_SHADOW,
        self::MODE_DRY_RUN,
        self::MODE_LIVE,
    ];
}
