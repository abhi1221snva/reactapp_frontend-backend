<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class ClientServers extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $connection = 'master';

    protected $table = "client_server";

    protected $fillable = ['client_id', 'ip_address', 'server_id'];

    public function client()
    {
        return $this->belongsTo("App\Model\Master\Client");
    }
}
