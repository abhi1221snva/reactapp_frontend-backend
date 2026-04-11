<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RVM v2 drop — a single voicemail delivery record.
 *
 * Lifecycle:
 *   queued → dispatching → delivered|failed
 *   queued → deferred → queued (scheduler re-queues when window opens)
 *   queued → cancelled
 *   anything → expired (lived past max lifetime)
 */
class Drop extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_drops';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'user_id', 'api_key_id', 'campaign_id', 'batch_id',
        'idempotency_key', 'phone_e164', 'caller_id', 'voice_template_id',
        'priority', 'status', 'deferred_until',
        'provider', 'provider_message_id', 'provider_cost_cents',
        'reservation_id', 'cost_cents', 'callback_url', 'metadata',
        'scheduled_at', 'dispatched_at', 'delivered_at', 'failed_at',
        'tries', 'last_error',
    ];

    protected $casts = [
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'deferred_until' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'tries' => 'int',
        'cost_cents' => 'int',
        'provider_cost_cents' => 'int',
    ];

    // Terminal states — never retried, never re-queued.
    public const TERMINAL_STATUSES = ['delivered', 'failed', 'cancelled', 'expired'];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
