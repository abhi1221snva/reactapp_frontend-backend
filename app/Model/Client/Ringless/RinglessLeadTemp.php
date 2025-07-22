<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessLeadTemp extends Model
{
    protected $table = "ringless_lead_temp";
    protected $fillable = ['campaign_id','list_id','lead_id'];
}