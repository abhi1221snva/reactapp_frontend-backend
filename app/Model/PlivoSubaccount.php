<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PlivoSubaccount extends Model
{
    protected $connection = 'master';
    protected $table      = 'plivo_subaccounts';
    protected $guarded    = ['id'];

    public function getAuthTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setAuthTokenAttribute(?string $value): void
    {
        $this->attributes['auth_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function account()
    {
        return $this->belongsTo(PlivoAccount::class, 'plivo_account_id');
    }
}
