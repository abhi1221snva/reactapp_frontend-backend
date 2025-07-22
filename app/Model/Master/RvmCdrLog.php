<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RvmCdrLog extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'rvm_cdr_log';
    public $timestamps = true;
}
