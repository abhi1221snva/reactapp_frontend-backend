<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class LeadSourceField extends Model
{
    public $timestamps = true;
    protected $table = 'crm_lead_source_fields';
    protected $fillable = [
        'lead_source_id',
        'field_name',
        'mapped_field_key',
        'field_label',
        'field_type',
        'is_required',
        'description',
        'allowed_values',
        'display_order',
        'status',
    ];

    protected $casts = [
        'allowed_values' => 'array',
        'is_required'    => 'boolean',
    ];
}
