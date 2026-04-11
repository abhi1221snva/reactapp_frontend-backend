<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_wallet';
    protected $primaryKey = 'client_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'client_id', 'balance_cents', 'reserved_cents',
        'low_balance_threshold_cents', 'low_balance_notified',
    ];

    protected $casts = [
        'balance_cents' => 'int',
        'reserved_cents' => 'int',
        'low_balance_threshold_cents' => 'int',
        'low_balance_notified' => 'bool',
    ];
}
