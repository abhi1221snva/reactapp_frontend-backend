<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for every field-level lead change.
 *
 * @property int         $id
 * @property int         $lead_id
 * @property string      $field_name
 * @property string|null $old_value
 * @property string|null $new_value
 * @property int|null    $updated_by
 * @property string      $user_type
 * @property string|null $ip_address
 */
class CrmLeadLog extends Model
{
    protected $table = 'crm_lead_logs';

    protected $fillable = [
        'lead_id',
        'field_name',
        'old_value',
        'new_value',
        'updated_by',
        'user_type',
        'ip_address',
    ];

    protected $casts = [
        'lead_id'    => 'integer',
        'updated_by' => 'integer',
    ];
}
