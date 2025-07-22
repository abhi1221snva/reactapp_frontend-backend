<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class meeting extends Model
{
    protected $connection = 'master';
    protected $table = 'meetings';
}
