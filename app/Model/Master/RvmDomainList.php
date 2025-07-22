<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RvmDomainList extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'rvm_domain_logs';
    protected $fillable = ['id', 'folder_link', 'callback_url'];
    public $timestamps = true;
}
