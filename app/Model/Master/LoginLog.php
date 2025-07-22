<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'login_logs';
    public $timestamps = false;
}
