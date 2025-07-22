<?php


namespace App\Model\Master;


use Illuminate\Database\Eloquent\Model;

class VoipConfiguration extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = "voip_configuration";

    protected $fillable = ['id','user_extension_id','trunk_id','parent_id','disallow','allow','context','name','host','username','secret','prefix','nat'];

}
