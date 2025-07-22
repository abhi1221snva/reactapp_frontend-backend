<?php


namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class MysqlConnections extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $connection = 'master';

    protected $table = 'mysql_connection';
}
