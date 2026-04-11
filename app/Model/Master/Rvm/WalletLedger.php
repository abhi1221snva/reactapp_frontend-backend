<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

class WalletLedger extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_wallet_ledger';
    public $timestamps = false;
    protected static $unguarded = true;

    const CREATED_AT = 'created_at';

    protected $casts = [
        'amount_cents' => 'int',
        'balance_after' => 'int',
        'created_at' => 'datetime',
    ];
}
