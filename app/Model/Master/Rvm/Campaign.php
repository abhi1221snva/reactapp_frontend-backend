<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_campaigns';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'client_id', 'created_by_user_id', 'created_by_api_key_id',
        'name', 'description', 'status',
        'caller_id', 'voice_template_id', 'provider_strategy', 'pinned_provider',
        'quiet_start', 'quiet_end', 'respect_dnc',
        'max_per_minute',
        'scheduled_start', 'started_at', 'completed_at',
        'stats_cache',
    ];

    protected $casts = [
        'stats_cache' => 'array',
        'respect_dnc' => 'bool',
        'scheduled_start' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'max_per_minute' => 'int',
    ];

    public function drops(): HasMany
    {
        return $this->hasMany(Drop::class, 'campaign_id');
    }
}
