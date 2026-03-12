<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base lead record — maps to crm_leads table.
 * Dynamic field values are stored in CrmLeadValue (crm_lead_values).
 *
 * @property int         $id
 * @property string      $lead_status
 * @property string|null $lead_type
 * @property int|null    $assigned_to
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property int|null    $lead_source_id
 * @property int         $lead_parent_id
 * @property string|null $unique_token
 * @property string|null $unique_url
 * @property int         $score
 * @property int         $is_deleted
 */
class CrmLeadRecord extends Model
{
    use SoftDeletes;

    protected $table = 'crm_leads';

    protected $fillable = [
        'lead_status',
        'lead_type',
        'assigned_to',
        'created_by',
        'updated_by',
        'lead_source_id',
        'lead_parent_id',
        'unique_token',
        'unique_url',
        'score',
        'is_deleted',
        'group_id',
        'opener_id',
        'closer_id',
        'is_copied',
        'copy_lead_id',
    ];

    protected $casts = [
        'score'          => 'integer',
        'is_deleted'     => 'integer',
        'lead_parent_id' => 'integer',
    ];

    /** EAV values for this lead. */
    public function values()
    {
        return $this->hasMany(CrmLeadValue::class, 'lead_id');
    }

    /** Return EAV values as a flat key→value array. */
    public function fieldValues(): array
    {
        return $this->values->pluck('field_value', 'field_key')->toArray();
    }
}
