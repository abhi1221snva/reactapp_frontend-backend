<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

/**
 * Shadow-mode observability record.
 *
 * Written by RvmShadowService every time the legacy pipeline processes a
 * drop while the tenant is in shadow / dry_run / live mode. Used by
 * `php artisan rvm:shadow-report` to diff legacy vs new-pipeline behaviour
 * BEFORE operators flip a tenant to live.
 *
 * @property int    $id
 * @property int    $client_id
 * @property int|null $legacy_rvm_cdr_log_id
 * @property string $phone_e164
 * @property string|null $caller_id
 * @property \Illuminate\Support\Carbon $legacy_dispatched_at
 * @property bool   $would_dispatch
 * @property string|null $would_provider
 * @property int|null    $would_cost_cents
 * @property string|null $would_reject_reason
 * @property array|null  $divergence_flags
 * @property array|null  $legacy_payload
 * @property \Illuminate\Support\Carbon $created_at
 */
class ShadowLog extends Model
{
    protected $connection = 'master';
    protected $table      = 'rvm_shadow_log';
    public    $timestamps = false;   // only created_at (DB default)

    protected $fillable = [
        'client_id',
        'legacy_rvm_cdr_log_id',
        'phone_e164',
        'caller_id',
        'legacy_dispatched_at',
        'would_dispatch',
        'would_provider',
        'would_cost_cents',
        'would_reject_reason',
        'divergence_flags',
        'legacy_payload',
    ];

    protected $casts = [
        'legacy_dispatched_at' => 'datetime',
        'created_at'           => 'datetime',
        'would_dispatch'       => 'bool',
        'would_cost_cents'     => 'int',
        'divergence_flags'     => 'array',
        'legacy_payload'       => 'array',
    ];
}
