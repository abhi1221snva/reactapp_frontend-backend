<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLeadFieldValue extends Model
{
    public $timestamps = true;
    protected $table = "crm_lead_field_values";
    protected $fillable = [
        'lead_id', 'label_id', 'column_name',
        'value_text', 'value_number', 'value_date', 'value_datetime',
    ];
}
