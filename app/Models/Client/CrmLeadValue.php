<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * EAV value row — maps to crm_lead_values table.
 *
 * @property int         $id
 * @property int         $lead_id
 * @property string      $field_key
 * @property string|null $field_value
 */
class CrmLeadValue extends Model
{
    protected $table = 'crm_lead_values';

    protected $fillable = [
        'lead_id',
        'field_key',
        'field_value',
    ];

    public function lead()
    {
        return $this->belongsTo(CrmLeadRecord::class, 'lead_id');
    }
}
