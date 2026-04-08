<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

class CrmVisibilitySetting extends Model
{
    public $timestamps = false;

    protected $table = 'crm_visibility_settings';

    protected $fillable = [
        'enable_team_visibility',
        'enable_hierarchy_visibility',
        'enable_creator_visibility',
        'enable_multi_assignee_visibility',
        'non_admin_min_level',
        'updated_by',
    ];

    protected $casts = [
        'enable_team_visibility'           => 'boolean',
        'enable_hierarchy_visibility'      => 'boolean',
        'enable_creator_visibility'        => 'boolean',
        'enable_multi_assignee_visibility' => 'boolean',
        'non_admin_min_level'              => 'integer',
    ];
}
