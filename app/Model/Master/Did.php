<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Did extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
	protected $table = 'did';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $fillable = ['parent_id', 'cli','user_id','area_code','country_code','provider','voip_provider'];

	

    public function servers()
    {
        return $this->hasMany("App\Model\Users");
    }
}
