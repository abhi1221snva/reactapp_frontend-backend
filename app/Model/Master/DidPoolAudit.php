<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class DidPoolAudit extends Model
{
    protected $connection = 'master';
    protected $table      = 'did_pool_audit';
    public $timestamps    = false;

    protected $fillable = [
        'did_pool_id', 'phone_number', 'action', 'from_status',
        'to_status', 'client_id', 'performed_by', 'triggered_by',
        'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Static factory for recording audit entries.
     * Uses raw DB insert for consistency with DidPoolService's query builder usage.
     */
    public static function record(
        int     $didPoolId,
        string  $phoneNumber,
        string  $action,
        ?string $fromStatus,
        string  $toStatus,
        ?int    $clientId = null,
        ?int    $performedBy = null,
        string  $triggeredBy = 'system',
        ?array  $metadata = null
    ): void {
        \Illuminate\Support\Facades\DB::connection('master')->table('did_pool_audit')->insert([
            'did_pool_id'  => $didPoolId,
            'phone_number' => $phoneNumber,
            'action'       => $action,
            'from_status'  => $fromStatus,
            'to_status'    => $toStatus,
            'client_id'    => $clientId,
            'performed_by' => $performedBy,
            'triggered_by' => $triggeredBy,
            'metadata'     => $metadata ? json_encode($metadata) : null,
            'created_at'   => now(),
        ]);
    }
}
