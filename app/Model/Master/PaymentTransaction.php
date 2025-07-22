<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $connection = 'master';
    protected $table = 'payment_transactions';

}
