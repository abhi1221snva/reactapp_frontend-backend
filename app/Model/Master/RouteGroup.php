<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RouteGroup extends Model
{
    protected $connection = 'master';
    protected $table = 'route_groups';

    protected $fillable = [
        'key', 'name', 'url_patterns', 'engine', 'description', 'display_order',
    ];

    protected $casts = [
        'url_patterns' => 'array',
    ];
}
