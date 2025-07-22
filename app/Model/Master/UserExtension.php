<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class UserExtension extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $connection = 'master';
    protected $table = 'user_extensions';
}
