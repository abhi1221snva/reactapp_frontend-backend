<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only event log. Never update or delete.
 */
class Event extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_events';
    public $timestamps = false;

    protected $fillable = [
        'drop_id', 'client_id', 'type', 'provider', 'payload', 'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}
