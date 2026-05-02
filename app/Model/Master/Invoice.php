<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $connection = 'master';
    protected $table = 'invoices';

    protected $fillable = [
        'client_id',
        'stripe_invoice_id',
        'stripe_subscription_id',
        'type',
        'status',
        'amount_due',
        'amount_paid',
        'currency',
        'hosted_invoice_url',
        'invoice_pdf_url',
        'period_start',
        'period_end',
        'paid_at',
    ];

    protected $casts = [
        'client_id'   => 'integer',
        'amount_due'  => 'integer',
        'amount_paid' => 'integer',
        'period_start' => 'datetime',
        'period_end'   => 'datetime',
        'paid_at'      => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
