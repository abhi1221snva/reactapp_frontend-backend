<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Field configuration model — maps to crm_labels table.
 *
 * @property int              $id
 * @property string           $label_name
 * @property string           $field_key
 * @property string           $field_type     text|email|phone_number|date|dropdown|radio|checkbox|textarea|number
 * @property string           $section
 * @property array|null       $options
 * @property string|null      $placeholder
 * @property array|null       $conditions
 * @property bool             $required
 * @property string|null      $apply_to       null=all forms | affiliate | merchant | both
 * @property array|null       $required_in    null=fallback to required bool | ["system","affiliate","merchant"]
 * @property int              $display_order
 * @property bool             $status
 */
class CrmLeadLabel extends Model
{
    protected $table = 'crm_labels';

    protected $fillable = [
        'label_name',
        'field_key',
        'field_type',
        'section',
        'options',
        'placeholder',
        'conditions',
        'required',
        'apply_to',
        'required_in',
        'display_order',
        'status',
    ];

    protected $casts = [
        'options'       => 'array',
        'conditions'    => 'array',
        'required_in'   => 'array',
        'required'      => 'boolean',
        'status'        => 'boolean',
        'display_order' => 'integer',
    ];

    /** Return only active fields ordered for display. */
    public function scopeActive($query)
    {
        return $query->where('status', true)->orderBy('display_order');
    }
}
