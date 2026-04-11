<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

class Dnc extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_dnc';
    public $timestamps = false;
    protected static $unguarded = true;

    const CREATED_AT = 'created_at';

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
