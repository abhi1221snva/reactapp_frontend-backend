<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmAgentBonus extends Model
{
    protected $table = 'crm_agent_bonuses';

    protected $fillable = [
        'agent_id',
        'bonus_type',
        'description',
        'amount',
        'period',
        'status',
        'paid_at',
        'created_by',
    ];

    protected $casts = [
        'amount'  => 'float',
        'paid_at' => 'datetime',
    ];
}
