<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_webhook_endpoints';

    protected $fillable = [
        'client_id', 'url', 'secret', 'events', 'active',
        'failure_count', 'disabled_at', 'disabled_reason',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'active' => 'bool',
        'failure_count' => 'int',
        'disabled_at' => 'datetime',
    ];

    public function subscribesTo(string $eventType): bool
    {
        if (!$this->events) return true;
        foreach ($this->events as $pattern) {
            if ($pattern === '*') return true;
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -2);
                if (str_starts_with($eventType, $prefix . '.')) return true;
            } elseif ($pattern === $eventType) {
                return true;
            }
        }
        return false;
    }
}
