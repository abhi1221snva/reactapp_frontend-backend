<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class SidebarMenuItem extends Model
{
    protected $connection = 'master';
    protected $table = 'sidebar_menu_items';

    protected $fillable = [
        'engine', 'section_label', 'route_path', 'label', 'icon_name',
        'route_group_key', 'min_level', 'display_order', 'is_active', 'badge_source',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_level' => 'integer',
        'display_order' => 'integer',
    ];
}
