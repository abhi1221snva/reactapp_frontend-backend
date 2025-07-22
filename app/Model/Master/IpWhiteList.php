<?php


namespace App\Model\Master;


use App\Model\Traits\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;

class IpWhiteList extends Model
{
    use HasCompositePrimaryKey;
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'ip_whitelists';
    protected $primaryKey = ['server_ip', 'whitelist_ip'];
}
