<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmCommissionRule extends Model
{
    protected $table = 'crm_commission_rules';

    protected $fillable = [
        'name',
        'lender_id',
        'deal_type',
        'commission_type',
        'value',
        'min_funded_amount',
        'max_funded_amount',
        'split_agent_pct',
        'agent_role',
        'priority',
        'status',
        'created_by',
    ];

    protected $casts = [
        'value'              => 'float',
        'min_funded_amount'  => 'float',
        'max_funded_amount'  => 'float',
        'split_agent_pct'    => 'float',
    ];
}
