<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for batched field-level lead changes.
 *
 * @property int         $id
 * @property int         $lead_id
 * @property string      $batch_id
 * @property string      $source
 * @property int|null    $user_id
 * @property string      $user_type
 * @property array       $changes
 * @property string|null $ip_address
 * @property string|null $summary
 * @property string      $created_at
 */
class LeadChangeLog extends Model
{
    protected $table = 'lead_change_logs';

    public $timestamps = false;

    protected $fillable = [
        'lead_id',
        'batch_id',
        'source',
        'user_id',
        'user_type',
        'changes',
        'ip_address',
        'summary',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'user_id' => 'integer',
        'changes' => 'array',
    ];
}
