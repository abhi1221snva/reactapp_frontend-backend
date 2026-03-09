<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmPipelineView extends Model
{
    public $timestamps = true;
    protected $table = "crm_pipeline_views";
    protected $fillable = [
        'name', 'user_id', 'is_default', 'is_shared', 'view_type',
        'filters', 'column_config', 'sort_config', 'status_columns', 'created_by',
    ];
    protected $casts = [
        'filters'        => 'array',
        'column_config'  => 'array',
        'sort_config'    => 'array',
        'status_columns' => 'array',
        'is_default'     => 'boolean',
        'is_shared'      => 'boolean',
    ];
}
