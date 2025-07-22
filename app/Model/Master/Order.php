<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $connection = 'master';
    protected $table = 'orders';
}
