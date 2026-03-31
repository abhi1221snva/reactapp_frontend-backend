<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class LenderOffer extends Model
{
    protected $table   = 'lender_offers';
    protected $guarded = ['id'];

    protected $casts = [
        'raw_offer'    => 'array',
        'raw_pricing'  => 'array',
        'confirmed_at' => 'datetime',
    ];

    protected $fillable = [
        'lead_id', 'business_id', 'lender_name', 'offer_id', 'product_type',
        'loan_amount', 'term_months', 'factor_rate', 'apr', 'payment_frequency',
        'payment_amount', 'origination_fee', 'total_payback', 'status',
        'raw_offer', 'raw_pricing', 'confirmed_at',
    ];
}
