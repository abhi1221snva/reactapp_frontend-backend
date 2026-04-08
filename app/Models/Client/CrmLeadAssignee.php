<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLeadAssignee extends Model
{
    public $timestamps = false;

    protected $table = 'crm_lead_assignees';

    protected $fillable = [
        'lead_id',
        'user_id',
        'role',
        'assigned_at',
        'assigned_by',
        'is_active',
    ];

    protected $casts = [
        'lead_id'     => 'integer',
        'user_id'     => 'integer',
        'assigned_by' => 'integer',
        'is_active'   => 'boolean',
    ];

    public function lead()
    {
        return $this->belongsTo(CrmLeadRecord::class, 'lead_id');
    }
}
