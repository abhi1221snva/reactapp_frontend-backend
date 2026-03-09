<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLeadActivity extends Model
{
    public $timestamps = true;
    protected $table = "crm_lead_activity";
    protected $fillable = [
        'lead_id', 'user_id', 'activity_type', 'subject', 'body',
        'meta', 'source_type', 'source_id', 'is_pinned',
    ];
    protected $casts = [
        'meta'      => 'array',
        'is_pinned' => 'boolean',
    ];
}
