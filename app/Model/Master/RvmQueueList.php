<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RvmQueueList extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'rvm_queue_list';
    public $timestamps = true;
}
