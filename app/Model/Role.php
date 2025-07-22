<?php


namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $connection = 'master';

    const USER_LEVEL_AGENT        = 1;
    const USER_LEVEL_ASSOCIATE    = 3;
    const USER_LEVEL_MANAGER      = 5;
    const USER_LEVEL_ADMIN        = 7;
    const USER_LEVEL_SUPER_ADMIN  = 9;

    public static function getAll()
    {
        $permissionRoles = Role::all();
    }


}
