<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Auto-generated note attached to a lead.
 *
 * @property int         $id
 * @property int         $lead_id
 * @property string      $note
 * @property string      $note_type
 * @property int|null    $created_by
 * @property string      $user_type
 */
class CrmLeadNote extends Model
{
    protected $table = 'crm_lead_notes';

    protected $fillable = [
        'lead_id',
        'note',
        'note_type',
        'created_by',
        'user_type',
    ];

    protected $casts = [
        'lead_id'    => 'integer',
        'created_by' => 'integer',
    ];
}
