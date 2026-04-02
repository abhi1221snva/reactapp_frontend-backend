<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

class EmailParseAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'email_parse_audit_log';

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'gmail_message_id',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'user_id'    => 'integer',
        'entity_id'  => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Quick helper to create an audit log entry.
     */
    public static function log(
        string $connection,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $gmailMessageId = null,
        ?array $metadata = null,
        ?string $ipAddress = null
    ): void {
        static::on($connection)->create([
            'user_id'          => $userId,
            'action'           => $action,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'gmail_message_id' => $gmailMessageId,
            'metadata'         => $metadata,
            'ip_address'       => $ipAddress,
            'created_at'       => now(),
        ]);
    }
}
