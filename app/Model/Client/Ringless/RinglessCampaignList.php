<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessCampaignList extends Model
{
    public $timestamps = true;
    protected $table = "ringless_campaign_list";
    protected $primaryKey = 'list_id';
    protected $fillable = ['campaign_id','list_id','is_deleted','status'];
    public function ringlessCampaign()
    {
        return $this->belongsTo(RinglessCampaign::class, 'campaign_id', 'id');
    }

    // Define the relationship to RinglessList model
    public function ringlessList()
    {
        return $this->belongsTo(RinglessList::class, 'list_id', 'id');
    }
}