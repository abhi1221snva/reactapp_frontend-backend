<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class OrdersItem extends Model
{
    protected $connection = 'master';
    protected $table = 'orders_items';
}
