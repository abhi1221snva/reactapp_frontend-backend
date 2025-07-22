<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLeadLenderApi extends Model
{
    protected $table = 'crm_lead_lender_api';

    protected $fillable = [
        'lead_id',
        'lender_id',
        'client_id',
        'lender_api_type',
        'businessID',
        'created_at',
        'updated_at'
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function lender()
    {
        return $this->belongsTo(CrmLenderAPis::class, 'lender_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
