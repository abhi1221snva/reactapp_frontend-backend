<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserHierarchy extends Model
{
    protected $connection = 'master';

    protected $table = 'user_hierarchy';

    protected $fillable = [
        'user_id',
        'manager_id',
        'client_id',
    ];

    protected $casts = [
        'user_id'    => 'integer',
        'manager_id' => 'integer',
        'client_id'  => 'integer',
    ];
}
