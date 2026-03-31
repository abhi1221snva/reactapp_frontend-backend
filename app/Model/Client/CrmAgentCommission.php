<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmAgentCommission extends Model
{
    protected $table = 'crm_agent_commissions';

    const STATUSES = ['pending', 'approved', 'paid', 'clawback'];

    protected $fillable = [
        'deal_id',
        'lead_id',
        'agent_id',
        'rule_id',
        'agent_role',
        'deal_type',
        'funded_amount',
        'commission_type',
        'commission_rate',
        'gross_commission',
        'agent_commission',
        'company_commission',
        'override_amount',
        'override_from',
        'status',
        'pay_period_start',
        'pay_period_end',
        'approved_at',
        'approved_by',
        'paid_at',
        'paid_by',
        'notes',
    ];

    protected $casts = [
        'funded_amount'      => 'float',
        'commission_rate'    => 'float',
        'gross_commission'   => 'float',
        'agent_commission'   => 'float',
        'company_commission' => 'float',
        'override_amount'    => 'float',
        'approved_at'        => 'datetime',
        'paid_at'            => 'datetime',
    ];
}
