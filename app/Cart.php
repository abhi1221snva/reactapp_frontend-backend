<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'cart';

    public static $billingPeriod = [
        1 => "Monthly",
        2 => "Quarterly",
        3 => "Half Yearly",
        4 => "Yearly"
    ];

    public static $billingMonths = [
        1 => 1,
        2 => 3,
        3 => 6,
        4 => 12
    ];
}
