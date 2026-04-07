<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RoleRoutePermission extends Model
{
    protected $connection = 'master';
    protected $table = 'role_route_permissions';

    public $timestamps = false;

    protected $fillable = [
        'role_id', 'route_group_key',
    ];
}
