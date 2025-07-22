<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SipChannelProvider extends Model
{
  use SoftDeletes;
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
   // public $timestamps = false;

    protected $table = "sip_channel_provider";

    protected $fillable = ['id','title','channel_provider','status','is_deleted','deleted_at'];

}