<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessList extends Model
{
    public $timestamps = true;
    protected $table = "ringless_list";
    protected $fillable = ['id','title','campaign_id','total_leads','file_name','status'];
    public function ringlessListData()
    {
        return $this->hasMany(RinglessListData::class, 'list_id');
    }
    public function ringlessLeadReport()
    {
        return $this->hasMany(RinglessLeadReport::class, 'list_id');
    }

}
