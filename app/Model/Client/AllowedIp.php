<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class AllowedIp extends Model
{
    public $timestamps = false;
    protected $table = "allowed_ip";

    protected $fillable = ['id','ip_address','label','status','is_primary'];

}
